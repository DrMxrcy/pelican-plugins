<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayerProfilePage;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Support\ModerationLists;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 29 step 2: the Banned, Operators and Whitelist tabs come from the server's own list
 * files, read through the size-checked daemon reader, cached for 30 seconds, and a broken
 * file shows an error instead of crashing the page.
 */
class ModerationListsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private const B = '069a79f4-44e9-4726-a5be-fca90e38aaf5';

    private Server $server;

    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $sizes = [];

    private bool $daemonDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
        $this->registerServerPageRoutes([PlayersPage::class, PlayerProfilePage::class]);
        $this->app->instance(Connector::class, new FakeConnector());
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        Http::fake(function (Request $request) {
            if ($this->daemonDown) {
                return Http::response('', 502);
            }

            return FakeDaemonFiles::respond($request, $this->files, $this->sizes) ?? Http::response('unexpected', 500);
        });

        $this->files['banned-players.json'] = (string) json_encode([
            ['uuid' => self::A, 'name' => 'Griefer', 'created' => '2026-11-01 10:00:00 +0000', 'source' => 'Server', 'expires' => 'forever', 'reason' => 'Burned the <b>spawn</b>'],
        ]);
        $this->files['ops.json'] = (string) json_encode([
            ['uuid' => self::B, 'name' => 'Admin_1', 'level' => 4, 'bypassesPlayerLimit' => false],
        ]);
        $this->files['whitelist.json'] = (string) json_encode([
            ['uuid' => self::A, 'name' => 'Friend'],
            ['uuid' => self::B, 'name' => 'Buddy'],
        ]);

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

    private function reads(string $file): int
    {
        return FakeDaemonFiles::contentReads($file);
    }

    public function test_parse_keeps_valid_entries_and_skips_garbage(): void
    {
        $entries = ModerationLists::parse((string) json_encode([
            ['uuid' => self::A, 'name' => 'Zed', 'level' => 9],
            'not an entry',
            ['uuid' => 'nope', 'name' => 'alpha', 'level' => '4'],
            ['uuid' => self::B],
            ['name' => ['array']],
            ['name' => "Evil\nName\u{2028}x", 'reason' => str_repeat('r', 500), 'created' => 'yesterday', 'expires' => '2027-01-01 00:00:00 +0000'],
            ['name' => '   '],
        ]));

        $this->assertNotNull($entries);
        $this->assertSame(['alpha', 'Evil Name x', 'Zed'], array_column($entries, 'name'));
        $this->assertNull($entries[0]['uuid']);
        $this->assertNull($entries[0]['level']);
        $this->assertSame(4, $entries[2]['level']);
        $this->assertSame(self::A, $entries[2]['uuid']);
        $this->assertSame(200, mb_strlen((string) $entries[1]['reason']));
        $this->assertNull($entries[1]['created']);
        $this->assertSame(Carbon::parse('2027-01-01 00:00:00 +0000')->getTimestamp(), $entries[1]['expires']);
    }

    public function test_parse_rejects_anything_that_is_not_a_json_list(): void
    {
        foreach (['', 'not json', '{"name":"x"}', '"x"', '[1,2', '{', str_repeat('[', 100) . str_repeat(']', 100)] as $content) {
            $this->assertNull(ModerationLists::parse($content), $content);
        }
        $this->assertSame([], ModerationLists::parse('[]'));
    }

    public function test_reads_are_cached_for_30_seconds_and_forget_clears_them(): void
    {
        $this->assertSame('Admin_1', ModerationLists::read($this->server, ModerationLists::OPS)['entries'][0]['name']);
        ModerationLists::read($this->server, ModerationLists::OPS);
        $this->assertSame(1, $this->reads('ops.json'));

        Carbon::setTestNow(now()->addSeconds(31));
        ModerationLists::read($this->server, ModerationLists::OPS);
        $this->assertSame(2, $this->reads('ops.json'));

        ModerationLists::forget($this->server);
        ModerationLists::read($this->server, ModerationLists::OPS);
        $this->assertSame(3, $this->reads('ops.json'));
    }

    public function test_missing_file_is_an_empty_list_and_failures_are_errors_cached_too(): void
    {
        unset($this->files['whitelist.json']);
        $this->assertSame(['state' => ModerationLists::STATE_MISSING, 'entries' => []], ModerationLists::read($this->server, ModerationLists::WHITELIST));

        $this->daemonDown = true;
        $this->assertSame(ModerationLists::STATE_ERROR, ModerationLists::read($this->server, ModerationLists::BANNED)['state']);
        $listings = FakeDaemonFiles::listings();
        ModerationLists::read($this->server, ModerationLists::BANNED);
        $this->assertSame($listings, FakeDaemonFiles::listings(), 'a failure is negative-cached');
    }

    public function test_oversized_file_is_never_downloaded(): void
    {
        $this->sizes['ops.json'] = 50_000_000;

        $this->assertSame(ModerationLists::STATE_ERROR, ModerationLists::read($this->server, ModerationLists::OPS)['state']);
        $this->assertSame(0, $this->reads('ops.json'));
    }

    public function test_owner_sees_each_list_tab(): void
    {
        Livewire::withQueryParams(['tab' => 'banned'])->test(PlayersPage::class)
            ->assertOk()
            ->assertSet('tab', 'banned')
            ->loadTable()
            ->assertSee(trans('players-plus::strings.lists.banned.heading'))
            ->assertSee('Griefer')
            ->assertSee('Burned the &lt;b&gt;spawn&lt;/b&gt;', false)
            ->assertDontSee('Burned the <b>spawn</b>', false)
            ->assertSee(trans('players-plus::strings.lists.forever'));

        Livewire::withQueryParams(['tab' => 'ops'])->test(PlayersPage::class)
            ->loadTable()
            ->assertSee('Admin_1')
            ->assertSee(self::B);

        Livewire::withQueryParams(['tab' => 'whitelist'])->test(PlayersPage::class)
            ->loadTable()
            ->assertSee('Friend')
            ->assertSee('Buddy');
    }

    public function test_malformed_ops_json_shows_an_error_and_the_page_still_renders(): void
    {
        $this->files['ops.json'] = '[{"name": "Admin_1", "level": 4},';

        Livewire::withQueryParams(['tab' => 'ops'])->test(PlayersPage::class)
            ->assertOk()
            ->loadTable()
            ->assertSee(trans('players-plus::strings.lists.error_title'))
            ->assertSee('ops.json')
            ->assertDontSee('Admin_1')
            ->assertSee(trans('players-plus::strings.tabs.online'));

        // An object instead of a list is malformed too.
        $this->files['ops.json'] = '{"Admin_1": 4}';
        ModerationLists::forget($this->server);
        Livewire::withQueryParams(['tab' => 'ops'])->test(PlayersPage::class)
            ->assertOk()
            ->loadTable()
            ->assertSee(trans('players-plus::strings.lists.error_title'));
    }

    public function test_missing_file_shows_the_empty_state_not_an_error(): void
    {
        unset($this->files['banned-players.json']);

        Livewire::withQueryParams(['tab' => 'banned'])->test(PlayersPage::class)
            ->assertOk()
            ->loadTable()
            ->assertSee(trans('players-plus::strings.lists.banned.empty'))
            ->assertDontSee(trans('players-plus::strings.lists.error_title'));
    }

    public function test_list_tabs_need_file_read_content(): void
    {
        $this->account([SubuserPermission::WebsocketConnect, SubuserPermission::FileRead, SubuserPermission::ControlConsole]);

        foreach (['banned', 'ops', 'whitelist'] as $tab) {
            Livewire::withQueryParams(['tab' => $tab])->test(PlayersPage::class)
                ->assertOk()
                ->assertSet('tab', PlayersPage::TAB_ONLINE)
                ->assertDontSee(trans('players-plus::strings.tabs.banned'))
                ->assertDontSee('Griefer')
                // Setting the property from the client is normalised as well.
                ->set('tab', $tab)
                ->assertSet('tab', PlayersPage::TAB_ONLINE);
        }

        $this->assertFalse(PlayersPage::canViewLists());
        $this->assertSame(0, $this->reads('.json'));
    }

    public function test_subuser_with_file_read_content_sees_the_tabs(): void
    {
        $this->account([SubuserPermission::WebsocketConnect, SubuserPermission::FileReadContent]);

        Livewire::withQueryParams(['tab' => 'whitelist'])->test(PlayersPage::class)
            ->assertOk()
            ->assertSet('tab', 'whitelist')
            ->loadTable()
            ->assertSee('Friend');
    }

    public function test_online_and_all_tabs_never_read_the_list_files(): void
    {
        Livewire::test(PlayersPage::class)->assertOk()->loadTable();
        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)->assertOk()->loadTable();

        Http::assertNothingSent();
    }
}
