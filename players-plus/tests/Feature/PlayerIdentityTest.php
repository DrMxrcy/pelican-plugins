<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\PlayerIdentity;
use DrMxrcy\PlayersPlus\Services\SessionTracker;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Plan 27 step 2: UUID from the Ping sample, else usercache.json (read through the faked
 * daemon, cached per server), else name-only; name changes and late UUIDs merge records.
 */
class PlayerIdentityTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const T0 = 1_800_000_000;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private Server $server;

    /** usercache.json content; null = 404 */
    private ?string $usercache = null;

    /** Size the directory listing reports for usercache.json; null = its real size. */
    private ?int $usercacheSize = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $files = $this->usercache === null ? [] : ['usercache.json' => $this->usercache];
            $sizes = $this->usercacheSize === null ? [] : ['usercache.json' => $this->usercacheSize];

            return FakeDaemonFiles::respond($request, $files, $sizes) ?? Http::response('unexpected', 500);
        });
        $this->server = $this->createServerModel();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0));
    }

    /** @param  array<string, string>|null  $usercache */
    private function resolve(string $name, ?string $uuid, int $minutes = 0, ?array $usercache = null): Player
    {
        return $this->app->make(PlayerIdentity::class)->resolve($this->server, $name, $uuid, Carbon::createFromTimestamp(self::T0 + $minutes * 60), $usercache);
    }

    private function usercacheReads(): int
    {
        return FakeDaemonFiles::contentReads('usercache.json');
    }

    /** @param  list<array<string, mixed>>  $players */
    private function poll(int $minutes, array $players): void
    {
        $sample = Sample::query()->create([
            'server_id' => $this->server->id,
            'polled_at' => Carbon::createFromTimestamp(self::T0 + $minutes * 60),
            'status' => SampleStatus::Online,
            'online' => count($players),
            'max' => 20,
            'players' => $players,
            'source' => 'query',
        ]);
        $this->app->make(SessionTracker::class)->track($this->server, $sample);
    }

    public function test_uuid_from_the_sample_is_used_without_reading_usercache(): void
    {
        $player = $this->resolve('jeb_', self::A);

        $this->assertSame(self::A, $player->uuid);
        $this->assertSame('jeb_', $player->name);
        $this->assertSame(0, $this->usercacheReads());
        $this->assertSame(0, FakeDaemonFiles::listings());
        $this->assertSame($player->id, $this->resolve('jeb_', self::A, 2)->id);
        $this->assertSame(1, Player::query()->count());
    }

    public function test_usercache_gives_the_uuid_and_is_read_once_per_server(): void
    {
        $this->usercache = (string) json_encode([
            ['name' => 'Dinnerbone', 'uuid' => '61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', 'expiresOn' => '2026-12-01 10:00:00 +0000'],
            ['name' => 'Grumm', 'uuid' => 'E6B5C088068044DF9E1B9BF11792291B', 'expiresOn' => '2026-12-01 10:00:00 +0000'],
        ]);

        $this->assertSame('61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', $this->resolve('Dinnerbone', null)->uuid);
        // Names are case-insensitive in Minecraft; UUIDs are stored dashed and lowercase.
        $this->assertSame('e6b5c088-0680-44df-9e1b-9bf11792291b', $this->resolve('grumm', null)->uuid);
        $this->assertNull($this->resolve('Nobody', null)->uuid);
        $this->assertSame(1, $this->usercacheReads());
    }

    public function test_unreadable_or_hostile_usercache_falls_back_to_name_only(): void
    {
        foreach ([null, 'not json', '{"name":"x"}', (string) json_encode([['name' => 'Evil name', 'uuid' => self::A], ['name' => 'Foo', 'uuid' => 'nope'], 'junk'])] as $i => $content) {
            cache()->flush();
            $this->usercache = $content;

            $player = $this->resolve('Foo', null, $i);

            $this->assertNull($player->uuid);
            $this->assertSame('Foo', $player->name);
        }
        $this->assertSame(1, Player::query()->count(), 'the same name-only record is found again');
        $this->assertNull(Player::query()->where('uuid', self::A)->first());
    }

    public function test_name_only_record_takes_the_uuid_when_it_appears(): void
    {
        $this->poll(0, [['name' => 'Alice', 'uuid' => null]]);
        $nameOnly = Player::query()->sole();
        $this->assertNull($nameOnly->uuid);

        $this->poll(2, [['name' => 'Alice', 'uuid' => self::A]]);

        $player = Player::query()->sole();
        $this->assertSame($nameOnly->id, $player->id);
        $this->assertSame(self::A, $player->uuid);
        $this->assertSame(1, PlayerSession::query()->count(), 'the session continues');
    }

    public function test_name_change_with_the_same_uuid_updates_the_record_and_keeps_the_old_name(): void
    {
        $this->poll(0, [['name' => 'Alice', 'uuid' => self::A]]);
        $this->poll(2, [['name' => 'Alicia', 'uuid' => self::A]]);

        $player = Player::query()->sole();
        $this->assertSame('Alicia', $player->name);
        $this->assertSame(['Alice'], $player->previous_names);
        $this->assertSame(1, PlayerSession::query()->whereNull('ended_at')->count(), 'a rename is not a leave');

        // Renaming back moves the current name out of the history instead of listing it twice.
        $this->poll(4, [['name' => 'Alice', 'uuid' => self::A]]);
        $player->refresh();
        $this->assertSame('Alice', $player->name);
        $this->assertSame(['Alicia'], $player->previous_names);
    }

    public function test_previous_names_are_capped(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->resolve("Name$i", self::A, $i);
        }

        $player = Player::query()->sole();
        $this->assertSame('Name29', $player->name);
        $this->assertCount(PlayerIdentity::MAX_PREVIOUS_NAMES, $player->previous_names ?? []);
        $this->assertSame('Name28', last($player->previous_names ?? []));
    }

    public function test_name_only_record_merges_into_the_known_uuid_after_a_rename(): void
    {
        // Known as OldName; later seen by Query under a new name nobody can map to a UUID yet.
        $this->poll(0, [['name' => 'OldName', 'uuid' => self::A]]);
        $this->poll(2, []);
        $this->poll(4, [['name' => 'NewName', 'uuid' => null]]);
        $this->poll(6, []);
        $this->assertSame(2, Player::query()->count());

        // The Ping sample finally pairs the new name with the UUID: one player again.
        $this->poll(8, [['name' => 'NewName', 'uuid' => self::A]]);

        $player = Player::query()->sole();
        $this->assertSame(self::A, $player->uuid);
        $this->assertSame('NewName', $player->name);
        $this->assertSame(['OldName'], $player->previous_names);
        $this->assertSame(240, $player->total_seconds);
        $this->assertSame(3, $player->sessions()->count());
        $this->assertTrue($player->first_seen_at->eq(Carbon::createFromTimestamp(self::T0)));
    }

    public function test_merge_keeps_a_single_open_session(): void
    {
        $known = $this->resolve('OldName', self::A);
        $nameOnly = $this->resolve('NewName', null);
        PlayerSession::query()->create(['player_id' => $known->id, 'started_at' => Carbon::createFromTimestamp(self::T0)]);
        PlayerSession::query()->create(['player_id' => $nameOnly->id, 'started_at' => Carbon::createFromTimestamp(self::T0 + 60)]);

        $player = $this->resolve('NewName', self::A, 2);

        $this->assertSame($known->id, $player->id);
        $this->assertSame(1, PlayerSession::query()->whereNull('ended_at')->count());
        $this->assertSame(self::T0, PlayerSession::query()->whereNull('ended_at')->sole()->started_at->getTimestamp());
    }

    public function test_records_are_per_server(): void
    {
        $other = $this->createServerModel();
        $mine = $this->resolve('jeb_', self::A);
        $theirs = $this->app->make(PlayerIdentity::class)->resolve($other, 'jeb_', self::A, now());

        $this->assertNotSame($mine->id, $theirs->id);
    }

    public function test_duplicate_usercache_names_resolve_to_the_newest_entry(): void
    {
        $old = '11111111-1111-4111-8111-111111111111';
        $new = '22222222-2222-4222-8222-222222222222';
        // Vanilla writes the most recently used entry first; a renamed-away holder lingers after it.
        $this->usercache = (string) json_encode([
            ['name' => 'Foo', 'uuid' => $new, 'expiresOn' => '2026-12-01 10:00:00 +0000'],
            ['name' => 'foo', 'uuid' => $old, 'expiresOn' => '2026-01-01 10:00:00 +0000'],
        ]);
        $this->assertSame(['foo' => $new], PlayerIdentity::parseUsercache($this->usercache));

        // Order does not matter when expiresOn is there...
        $this->assertSame(['foo' => $new], PlayerIdentity::parseUsercache((string) json_encode([
            ['name' => 'foo', 'uuid' => $old, 'expiresOn' => '2026-01-01 10:00:00 +0000'],
            ['name' => 'Foo', 'uuid' => $new, 'expiresOn' => '2026-12-01 10:00:00 +0000'],
        ])));
        // ...and without it the first (most recent) entry wins.
        $this->assertSame(['foo' => $new], PlayerIdentity::parseUsercache((string) json_encode([
            ['name' => 'Foo', 'uuid' => $new],
            ['name' => 'Foo', 'uuid' => $old, 'expiresOn' => 'garbage'],
        ])));

        $this->assertSame($new, $this->resolve('Foo', null)->uuid);
    }

    public function test_oversized_usercache_is_never_downloaded_and_the_refusal_is_cached(): void
    {
        $this->usercache = (string) json_encode([['name' => 'Foo', 'uuid' => self::A]]);
        $this->usercacheSize = 50_000_000;

        $this->assertNull($this->resolve('Foo', null)->uuid);
        $this->assertNull($this->resolve('Bar', null)->uuid);

        $this->assertSame(0, $this->usercacheReads(), 'the body must not be fetched');
        $this->assertSame(1, FakeDaemonFiles::listings(), 'the refusal is cached');
    }

    public function test_a_given_usercache_map_is_used_without_any_daemon_call(): void
    {
        $player = $this->resolve('Foo', null, usercache: ['foo' => self::A]);

        $this->assertSame(self::A, $player->uuid);
        Http::assertNothingSent();
    }

    public function test_a_shared_name_resolves_to_the_most_recently_seen_record(): void
    {
        $older = $this->resolve('Foo', '11111111-1111-4111-8111-111111111111', 0);
        $newer = $this->resolve('Foo', '22222222-2222-4222-8222-222222222222', 10);

        $this->assertSame($newer->id, $this->resolve('Foo', null, 20, usercache: [])->id);
        $this->assertNotSame($older->id, $newer->id);
    }
}
