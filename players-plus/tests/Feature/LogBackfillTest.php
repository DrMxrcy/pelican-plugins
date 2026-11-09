<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Enums\SessionSource;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\LogBackfill;
use DrMxrcy\PlayersPlus\Services\SessionTracker;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 step 3: log events merged into poll-built sessions. Times are UTC on 2026-11-09;
 * the default poll interval is 2 minutes, so the override window is 4 minutes.
 */
class LogBackfillTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private Server $server;

    private string $log = '';

    private string $modified = '2026-11-09T12:30:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createServerModel();
        Http::fake(fn (Request $request) => FakeDaemonFiles::respond($request, ['logs/latest.log' => $this->log], [], ['logs/latest.log' => $this->modified])
            ?? Http::response('', 404, ['User-Agent' => 'Pelican Wings/v1.0.0']));
        Carbon::setTestNow('2026-11-09 12:30:00');
    }

    private static function at(string $time): Carbon
    {
        return Carbon::parse("2026-11-09 $time");
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . "/Fixtures/logs/$name.log");
    }

    /** Appends lines to the log, as the server would. */
    private function logs(string ...$lines): void
    {
        $this->log .= implode('', array_map(fn ($line) => "$line\n", $lines));
        $this->modified = Carbon::parse($this->modified)->addSecond()->toIso8601String();
    }

    private function scan(): void
    {
        $this->app->make(LogBackfill::class)->scan($this->server);
    }

    /** @param  list<array{name: string, uuid: string|null}>  $players */
    private function poll(string $time, array $players, SampleStatus $status = SampleStatus::Online): void
    {
        $sample = Sample::query()->create([
            'server_id' => $this->server->id,
            'polled_at' => self::at($time),
            'status' => $status,
            'online' => $status === SampleStatus::Online ? count($players) : null,
            'max' => 20,
            'players' => $status === SampleStatus::Online ? $players : null,
            'source' => 'query',
        ]);
        $this->app->make(SessionTracker::class)->track($this->server, $sample);
    }

    /** @return array{name: string, uuid: string} */
    private static function alice(): array
    {
        return ['name' => 'Alice', 'uuid' => self::A];
    }

    private function player(string $name): Player
    {
        return Player::query()->where('server_id', $this->server->id)->where('name', $name)->sole();
    }

    /** @return list<array{string, string|null, string}> [start, end, source] as H:i:s */
    private function sessions(string $name): array
    {
        return $this->player($name)->sessions()->orderBy('started_at')->get()
            ->map(fn (PlayerSession $s) => [$s->started_at->format('H:i:s'), $s->ended_at?->format('H:i:s'), $s->source->value])
            ->all();
    }

    /** Playtime is never counted twice: totals match the closed sessions, which never overlap. */
    private function assertConsistent(): void
    {
        foreach (Player::query()->with('sessions')->get() as $player) {
            $closed = $player->sessions->whereNotNull('ended_at')->sortBy('started_at')->values();
            $this->assertSame($closed->sum(fn (PlayerSession $s) => $s->seconds()), $player->total_seconds, "$player->name total");

            $previousEnd = null;
            foreach ($player->sessions->sortBy('started_at') as $session) {
                if ($previousEnd !== null) {
                    $this->assertTrue($session->started_at->greaterThanOrEqualTo($previousEnd), "$player->name sessions overlap");
                }
                $previousEnd = $session->ended_at ?? Carbon::create(9999);
            }
        }
    }

    public function test_a_log_join_moves_a_poll_start_back_to_the_exact_time(): void
    {
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:00:30] [User Authenticator #1/INFO]: UUID of player Alice is ' . self::A, '[12:00:31] [Server thread/INFO]: Alice joined the game');

        $this->scan();

        $this->assertSame([['12:00:31', null, 'log']], $this->sessions('Alice'));
        $this->assertTrue($this->player('Alice')->first_seen_at->eq(self::at('12:00:31')));
        $this->assertConsistent();
    }

    public function test_a_join_outside_the_window_does_not_move_the_poll_start(): void
    {
        $this->poll('12:10:00', [self::alice()]);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');

        $this->scan();

        $this->assertSame([['12:10:00', null, 'poll']], $this->sessions('Alice'));
        $this->assertSame(['alice'], array_keys(LogCursor::query()->sole()->pending ?? []), 'kept pending for its leave');
    }

    public function test_a_log_leave_moves_a_poll_end_back_and_corrects_playtime(): void
    {
        foreach (['12:02:00', '12:04:00', '12:06:00', '12:08:00', '12:10:00'] as $time) {
            $this->poll($time, [self::alice()]);
        }
        $this->poll('12:12:00', []);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game', '[12:10:44] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertTrue($this->player('Alice')->last_seen_at->eq(self::at('12:10:44')));
        $this->assertConsistent();
    }

    public function test_a_log_leave_closes_the_open_poll_session(): void
    {
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:05:10] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $this->assertSame([['12:02:00', '12:05:10', 'log']], $this->sessions('Alice'));
        $this->assertSame(190, $this->player('Alice')->total_seconds);

        // The next poll no longer sees Alice and has nothing left to close.
        $this->poll('12:06:00', []);
        $this->assertSame([['12:02:00', '12:05:10', 'log']], $this->sessions('Alice'));
        $this->assertConsistent();
    }

    public function test_a_join_seen_before_the_poll_waits_and_is_merged_later(): void
    {
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');
        $this->scan();
        $this->assertSame(0, PlayerSession::query()->count());

        $this->poll('12:02:00', [self::alice()]);
        $this->scan(); // nothing new in the log; the pending join finds the poll session

        $this->assertSame([['12:00:31', null, 'log']], $this->sessions('Alice'));
        $this->assertNull(LogCursor::query()->sole()->pending);
        $this->assertConsistent();
    }

    public function test_a_fresh_install_backfills_earlier_joins_and_leaves(): void
    {
        $this->log = $this->fixture('vanilla')
            . "[12:15:00] [Server thread/INFO]: Bob joined the game\n"
            . "[12:20:00] [Server thread/INFO]: Bob left the game\n";

        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
        $this->assertSame([['12:15:00', '12:20:00', 'log']], $this->sessions('Bob'));
        $this->assertSame(self::A, $this->player('Alice')->uuid, 'from the UUID line');
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertSame(300, $this->player('Bob')->total_seconds);
        $this->assertConsistent();
    }

    public function test_scanning_the_same_log_again_adds_nothing(): void
    {
        $this->log = $this->fixture('vanilla');
        $this->scan();
        $before = PlayerSession::query()->get()->toArray();

        // Unchanged, re-downloaded (touched), and from scratch (cursor lost): all no-ops.
        $this->scan();
        $this->modified = '2026-11-09T12:31:00+00:00';
        $this->scan();
        LogCursor::query()->delete();
        $this->scan();

        $this->assertSame($before, PlayerSession::query()->get()->toArray());
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertConsistent();
    }

    public function test_a_rotated_log_restarts_at_zero_and_only_adds_its_own_events(): void
    {
        $this->log = $this->fixture('vanilla');
        $this->scan();

        $this->log = "[13:00:00] [Server thread/INFO]: Starting minecraft server version 1.21.4\n"
            . "[13:05:00] [Server thread/INFO]: Alice joined the game\n"
            . "[13:06:00] [Server thread/INFO]: Alice left the game\n";
        $this->modified = '2026-11-09T13:06:01+00:00';
        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log'], ['13:05:00', '13:06:00', 'log']], $this->sessions('Alice'));
        $this->assertSame(strlen($this->log), LogCursor::query()->sole()->offset);
        $this->assertConsistent();
    }

    public function test_chat_spoofs_create_nothing(): void
    {
        $this->log = $this->fixture('spoof');

        $this->scan();

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(0, PlayerSession::query()->count());
    }

    public function test_an_old_name_in_the_log_does_not_rename_the_player(): void
    {
        $this->poll('12:20:00', [['name' => 'Alicia', 'uuid' => self::A]]);
        $this->logs('[12:00:30] [User Authenticator #1/INFO]: UUID of player Alice is ' . self::A, '[12:00:31] [Server thread/INFO]: Alice joined the game', '[12:05:00] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $player = Player::query()->sole();
        $this->assertSame('Alicia', $player->name);
        $this->assertSame(['Alice'], $player->previous_names);
        $this->assertSame([['12:00:31', '12:05:00', 'log'], ['12:20:00', null, 'poll']], $this->sessions('Alicia'));
        $this->assertConsistent();
    }

    public function test_no_ip_address_is_stored_in_any_pp_table(): void
    {
        $this->log = $this->fixture('vanilla') . $this->fixture('paper') . $this->fixture('forge');
        $this->poll('12:02:00', [self::alice()]);

        $this->scan();
        $this->assertGreaterThan(0, PlayerSession::query()->count());

        $tables = array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => str_starts_with($table, 'pp_'));
        $this->assertContains('pp_log_cursors', $tables);
        foreach ($tables as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('203.0.113.7', $dump, $table);
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $dump, $table);
        }
    }

    public function test_sessions_from_poll_and_log_together_never_double_count(): void
    {
        // Alice rejoins between polls; Bob comes and goes between two polls entirely.
        $this->poll('12:00:00', []);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:02:40] [Server thread/INFO]: Bob joined the game', '[12:03:10] [Server thread/INFO]: Alice left the game');
        $this->scan();
        $this->logs('[12:03:20] [Server thread/INFO]: Alice joined the game', '[12:03:50] [Server thread/INFO]: Bob left the game');
        $this->poll('12:04:00', [self::alice()]);
        $this->scan();
        $this->poll('12:06:00', []);
        $this->logs('[12:05:30] [Server thread/INFO]: Alice left the game');
        $this->scan();
        $this->scan();

        $this->assertSame([['12:00:31', '12:03:10', 'log'], ['12:03:20', '12:05:30', 'log']], $this->sessions('Alice'));
        $this->assertSame([['12:02:40', '12:03:50', 'log']], $this->sessions('Bob'));
        $this->assertSame(159 + 130, $this->player('Alice')->total_seconds);
        $this->assertConsistent();
    }

    public function test_a_lost_lock_changes_nothing_and_the_next_scan_catches_up(): void
    {
        $this->log = $this->fixture('vanilla');
        $lock = Cache::lock("players-plus.track.{$this->server->id}", 60);
        $lock->get();
        $tracker = $this->app->make(SessionTracker::class);
        $tracker->lockWaitSeconds = 0;
        $this->app->instance(SessionTracker::class, $tracker);

        $this->scan();
        $this->assertSame(0, LogCursor::query()->count());
        $this->assertSame(0, PlayerSession::query()->count());

        $lock->release();
        $this->scan();
        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
    }

    public function test_a_session_source_label_exists_for_log(): void
    {
        $this->assertSame('Log', SessionSource::Log->getLabel());
    }
}
