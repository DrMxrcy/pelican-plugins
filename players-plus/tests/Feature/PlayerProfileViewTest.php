<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayerProfilePage;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 27 step 4: the player profile. Stats are read through the faked daemon on demand;
 * the game server is never contacted.
 */
class PlayerProfileViewTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private FakeConnector $connector;

    private Server $server;

    /** @var array<string, string> daemon files by path; missing = 404 */
    private array $files = [];

    /** @var array<string, int> sizes the directory listing reports, by path */
    private array $sizes = [];

    private bool $daemonDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
        $this->registerServerPageRoutes([PlayersPage::class, PlayerProfilePage::class]);
        $this->connector = new FakeConnector();
        $this->app->instance(Connector::class, $this->connector);
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        Http::fake(function (Request $request) {
            if ($this->daemonDown) {
                return Http::response('', 502);
            }

            return FakeDaemonFiles::respond($request, $this->files, $this->sizes) ?? Http::response('unexpected', 500);
        });
        $this->files['server.properties'] = "level-name=survival\n";

        $this->server = $this->account();
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  array<int, SubuserPermission>  $permissions  empty = the owner */
    private function account(array $permissions = []): Server
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => ['minecraft']])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();
        $server->refresh();
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    private function alice(?Server $server = null, ?string $uuid = self::A): Player
    {
        $player = Player::query()->create([
            'server_id' => ($server ?? $this->server)->id,
            'uuid' => $uuid,
            'name' => 'Alice',
            'previous_names' => ['Alicia', 'Al1ce'],
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now(),
            'total_seconds' => 3 * 3600 + 3600,
        ]);
        // 10 days ago: 3 h. 2 days ago: 1 h. Open since 30 min.
        foreach ([[10 * 1440, 10 * 1440 - 180], [2 * 1440, 2 * 1440 - 60], [30, null]] as [$start, $end]) {
            PlayerSession::query()->create([
                'player_id' => $player->id,
                'started_at' => now()->subMinutes($start),
                'ended_at' => $end === null ? null : now()->subMinutes($end),
            ]);
        }

        return $player;
    }

    private function onlineSample(int $minutesAgo = 1): void
    {
        Sample::query()->create([
            'server_id' => $this->server->id, 'polled_at' => now()->subMinutes($minutesAgo), 'status' => SampleStatus::Online,
            'online' => 1, 'max' => 20, 'players' => [['name' => 'Alice', 'uuid' => self::A]], 'source' => 'query',
        ]);
    }

    private function statsReads(): int
    {
        return FakeDaemonFiles::contentReads('/stats/');
    }

    private function profile(Player $player): Testable
    {
        return Livewire::withQueryParams(['player' => $player->id])->test(PlayerProfilePage::class);
    }

    public function test_profile_shows_identity_playtime_sessions_and_stats_from_the_stats_file(): void
    {
        $this->files['survival/stats/' . self::A . '.json'] = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/stats/' . self::A . '.json');
        $player = $this->alice();
        $this->onlineSample();

        $this->profile($player)
            ->assertOk()
            ->assertSee('Alice')
            ->assertSee(self::A)
            ->assertSee('Alicia')
            ->assertSee('Al1ce')
            ->assertSee(trans('players-plus::strings.all.online'))
            // Total: 4 h closed + 30 m open. Last 7 days: 1 h + 30 m.
            ->assertSee('4h 30m')
            ->assertSee('1h 30m')
            // Stats: 216000 ticks = 3 h, 4523100 cm = 45.23 km.
            ->assertSee(trans('players-plus::strings.stats.play_time'))
            ->assertSee('3h')
            ->assertSee('152')
            ->assertSee('2,048')
            ->assertSee('45.23 km')
            ->assertDontSee(trans('players-plus::strings.stats.none'))
            ->loadTable()
            ->assertCanSeeTableRecords($player->sessions()->get());

        $this->assertSame(1, $this->statsReads());
        $this->assertSame([], $this->connector->opened);
    }

    public function test_missing_stats_file_says_no_stats_yet(): void
    {
        $this->profile($this->alice())
            ->assertOk()
            ->assertSee(trans('players-plus::strings.stats.none'));
    }

    public function test_stats_are_cached_between_profile_opens(): void
    {
        $player = $this->alice();
        $this->files['survival/stats/' . self::A . '.json'] = '{"stats":{"minecraft:custom":{"minecraft:deaths":4}}}';

        $this->profile($player)->assertOk();
        $this->profile($player)->assertOk();

        $this->assertSame(1, $this->statsReads());
    }

    public function test_name_only_player_has_no_stats_and_reads_nothing(): void
    {
        $this->profile($this->alice(uuid: null))
            ->assertOk()
            ->assertSee(trans('players-plus::strings.stats.none'));

        Http::assertNothingSent();
    }

    public function test_daemon_failure_is_shown_without_an_error(): void
    {
        $this->daemonDown = true;

        $this->profile($this->alice())
            ->assertOk()
            ->assertSee(trans('players-plus::strings.stats.unavailable'));
    }

    public function test_stats_need_file_read_permission(): void
    {
        $server = $this->account([SubuserPermission::WebsocketConnect]);
        $this->files['survival/stats/' . self::A . '.json'] = '{"stats":{"minecraft:custom":{"minecraft:deaths":4}}}';

        $this->profile($this->alice($server))
            ->assertOk()
            ->assertSee('Alice')
            ->assertDontSee(trans('players-plus::strings.stats.deaths'));

        $this->assertSame(0, $this->statsReads());
    }

    public function test_players_of_other_servers_are_not_found(): void
    {
        $stranger = $this->alice($this->createServerModel());

        $this->profile($stranger)->assertNotFound();
        Livewire::test(PlayerProfilePage::class)->assertNotFound();
    }

    public function test_profile_needs_console_access(): void
    {
        $this->account([SubuserPermission::FileRead]);

        $this->assertFalse(PlayerProfilePage::canAccess());
    }

    public function test_profile_is_not_in_the_navigation(): void
    {
        $this->assertFalse(PlayerProfilePage::shouldRegisterNavigation());
        $this->assertStringStartsWith('players-plus', (string) PlayerProfilePage::getSlug());
    }

    public function test_all_players_rows_link_to_the_profile(): void
    {
        $player = $this->alice();

        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)
            ->loadTable()
            ->assertSee(PlayerProfilePage::getUrl(['player' => $player->id]), false);
    }

    public function test_oversized_stats_file_is_never_downloaded_and_the_refusal_is_cached(): void
    {
        $player = $this->alice();
        $this->files['survival/stats/' . self::A . '.json'] = '{"stats":{"minecraft:custom":{"minecraft:deaths":4}}}';
        $this->sizes['survival/stats/' . self::A . '.json'] = 500_000_000;

        $this->profile($player)->assertOk()->assertSee(trans('players-plus::strings.stats.unavailable'));
        $listings = FakeDaemonFiles::listings();
        $this->profile($player)->assertOk();

        $this->assertSame(0, $this->statsReads(), 'the body must not be fetched');
        $this->assertSame($listings, FakeDaemonFiles::listings(), 'the refusal is cached');
    }

    public function test_empty_stats_object_says_no_stats_yet(): void
    {
        $this->files['survival/stats/' . self::A . '.json'] = '{"stats":{},"DataVersion":4189}';

        $this->profile($this->alice())
            ->assertOk()
            ->assertSee(trans('players-plus::strings.stats.none'));
    }

    public function test_open_session_is_not_live_when_polling_is_stale(): void
    {
        $player = $this->alice();
        $player->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();
        $this->onlineSample(20);

        // Open session counts 30 - 20 = 10 min (to the last sighting): 4h 10m, not 4h 30m.
        $this->profile($player)
            ->assertOk()
            ->assertSee('4h 10m')
            ->assertDontSee('4h 30m')
            ->assertSee(trans('players-plus::strings.profile.last_seen', ['time' => '20 minutes ago']));
    }
}
