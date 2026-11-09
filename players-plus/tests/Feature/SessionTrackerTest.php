<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\SessionTracker;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Plan 27 step 1: sessions are built by comparing consecutive samples. Samples are written
 * directly here; the daemon is faked and must not be needed when samples carry UUIDs.
 */
class SessionTrackerTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const T0 = 1_800_000_000;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private const B = '069a79f4-44e9-4726-a5be-fca90e38aaf5';

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response('', 404));
        $this->server = $this->createServerModel();
    }

    /**
     * Stores a sample at T0 + $minutes and runs the tracker on it, like PollServerJob does.
     *
     * @param  list<array<string, mixed>>  $players
     */
    private function poll(int $minutes, SampleStatus $status = SampleStatus::Online, array $players = [], string $source = 'query', ?int $online = null): Sample
    {
        $sample = Sample::query()->create([
            'server_id' => $this->server->id,
            'polled_at' => $this->at($minutes),
            'status' => $status,
            'online' => $status === SampleStatus::Online ? ($online ?? count($players)) : null,
            'max' => $status === SampleStatus::Online ? 20 : null,
            'players' => $status === SampleStatus::Online ? $players : null,
            'source' => $status === SampleStatus::Online ? $source : null,
        ]);
        Carbon::setTestNow($this->at($minutes)->addSeconds(10));
        $this->app->make(SessionTracker::class)->track($this->server, $sample);

        return $sample;
    }

    private function at(int $minutes): Carbon
    {
        return Carbon::createFromTimestamp(self::T0 + $minutes * 60);
    }

    /** @return array{name: string, uuid: string|null} */
    private static function a(): array
    {
        return ['name' => 'Alice', 'uuid' => self::A];
    }

    /** @return array{name: string, uuid: string|null} */
    private static function b(): array
    {
        return ['name' => 'Bob', 'uuid' => self::B];
    }

    private function player(string $uuid): Player
    {
        return Player::query()->where('server_id', $this->server->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return list<array{0: int|null, 1: int|null}> [start, end] in minutes after T0, oldest first */
    private function sessions(string $uuid): array
    {
        return $this->player($uuid)->sessions()->orderBy('started_at')->get()
            ->map(fn (PlayerSession $s) => [
                intdiv($s->started_at->getTimestamp() - self::T0, 60),
                $s->ended_at === null ? null : intdiv($s->ended_at->getTimestamp() - self::T0, 60),
            ])->all();
    }

    public function test_a_joins_b_joins_a_leaves_server_goes_offline(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(2, players: [self::a(), self::b()]);
        $this->poll(4, players: [self::b()]);
        $this->poll(6, players: [self::b()]);
        $this->poll(8, SampleStatus::Offline);
        $this->poll(10, SampleStatus::Offline);

        // A: joined at 0, gone at 4. B: joined at 2, last online sample at 6.
        $this->assertSame([[0, 4]], $this->sessions(self::A));
        $this->assertSame([[2, 6]], $this->sessions(self::B));
        $this->assertSame(240, $this->player(self::A)->total_seconds);
        $this->assertSame(240, $this->player(self::B)->total_seconds);
        $this->assertSame(2, PlayerSession::query()->count());
        $this->assertSame(0, PlayerSession::query()->whereNull('ended_at')->count(), 'no session may stay open after the server stops');

        $a = $this->player(self::A);
        $this->assertSame('Alice', $a->name);
        $this->assertTrue($a->first_seen_at->eq($this->at(0)));
        $this->assertTrue($a->last_seen_at->eq($this->at(4)));
    }

    public function test_rejoining_opens_a_second_session_and_adds_up_playtime(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(2, players: []);
        $this->poll(4, players: [self::a()]);
        $this->poll(8, players: [self::a()]);
        $this->poll(10, players: []);

        $this->assertSame([[0, 2], [4, 10]], $this->sessions(self::A));
        $this->assertSame(120 + 360, $this->player(self::A)->total_seconds);
    }

    public function test_unreachable_closes_open_sessions_at_the_last_online_sample(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(2, players: [self::a()]);
        $this->poll(4, SampleStatus::Unreachable);

        $this->assertSame([[0, 2]], $this->sessions(self::A));
        $this->assertSame(120, $this->player(self::A)->total_seconds);
    }

    public function test_ping_only_partial_sample_never_closes_a_session_for_a_missing_player(): void
    {
        $sample = fn (string ...$names) => array_map(fn (string $n) => ['name' => $n, 'uuid' => null], $names);
        $twelve = $sample('P01', 'P02', 'P03', 'P04', 'P05', 'P06', 'P07', 'P08', 'P09', 'P10', 'P11', 'P12');

        $this->poll(0, players: [self::a(), ...array_slice($twelve, 0, 11)], source: 'ping', online: 20);
        // 20 online, Ping lists 12 others: Alice is just not in this sample.
        $this->poll(2, players: $twelve, source: 'ping', online: 20);
        $this->poll(4, players: array_reverse($twelve), source: 'ping', online: 20);

        $this->assertSame([[0, null]], $this->sessions(self::A));
        $this->assertSame(0, $this->player(self::A)->total_seconds);
        // Newly seen players in a partial sample still get a session.
        $p12 = Player::query()->where('server_id', $this->server->id)->where('name', 'P12')->firstOrFail();
        $this->assertSame(1, $p12->sessions()->whereNull('ended_at')->count());

        // A full sample (everyone listed) is trusted again: Alice really left.
        $this->poll(6, players: $twelve, source: 'ping', online: 12);
        $this->assertSame([[0, 6]], $this->sessions(self::A));
    }

    public function test_tracking_the_same_sample_twice_changes_nothing(): void
    {
        $this->poll(0, players: [self::a()]);
        $second = $this->poll(2, players: []);
        $this->app->make(SessionTracker::class)->track($this->server, $second);
        $this->app->make(SessionTracker::class)->track($this->server, $second);

        $this->assertSame([[0, 2]], $this->sessions(self::A));
        $this->assertSame(120, $this->player(self::A)->total_seconds);
    }

    public function test_a_sample_older_than_the_newest_one_is_ignored(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(4, players: [self::a()]);
        $late = Sample::query()->create([
            'server_id' => $this->server->id, 'polled_at' => $this->at(2), 'status' => SampleStatus::Online,
            'online' => 0, 'max' => 20, 'players' => [], 'source' => 'query',
        ]);

        $this->app->make(SessionTracker::class)->track($this->server, $late);

        $this->assertSame([[0, null]], $this->sessions(self::A));
    }

    public function test_a_gap_in_polling_closes_sessions_at_the_last_sample_before_it(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(2, players: [self::a()]);
        // Scheduler or worker was down for an hour: the hour must not count as playtime.
        $this->poll(62, players: [self::a()]);

        $this->assertSame([[0, 2], [62, null]], $this->sessions(self::A));
        $this->assertSame(120, $this->player(self::A)->total_seconds);
    }

    public function test_open_sessions_without_any_earlier_sample_close_at_last_seen(): void
    {
        $this->poll(0, players: [self::a()]);
        $this->poll(2, players: [self::a()]);
        // Every sample pruned (e.g. retention) while the session stayed open.
        Sample::query()->delete();

        $this->poll(300, players: [self::a()]);

        $this->assertSame([[0, 2], [300, null]], $this->sessions(self::A));
    }

    public function test_sessions_are_per_server(): void
    {
        $other = $this->createServerModel();
        $this->poll(0, players: [self::a()]);
        $sample = Sample::query()->create([
            'server_id' => $other->id, 'polled_at' => $this->at(2), 'status' => SampleStatus::Offline,
        ]);
        $this->app->make(SessionTracker::class)->track($other, $sample);

        $this->assertSame([[0, null]], $this->sessions(self::A));
    }

    public function test_invalid_stored_entries_are_skipped(): void
    {
        $this->poll(0, players: [['name' => 'bad name!', 'uuid' => null], ['name' => '', 'uuid' => null], self::a(), ['uuid' => self::B]]);

        $this->assertSame(1, Player::query()->count());
        $this->assertSame([[0, null]], $this->sessions(self::A));
    }
}
