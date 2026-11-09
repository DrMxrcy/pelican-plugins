<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Admin\Pages\PlayersOverviewPage;
use DrMxrcy\PlayersPlus\Filament\Admin\Widgets\PlayersOverviewChartWidget;
use DrMxrcy\PlayersPlus\Filament\Admin\Widgets\PlayersOverviewStatsWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Providers\PlayersPlusServiceProvider;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 34 step 2: the admin-panel Players overview. Admin panel only, gated by the
 * `view players-plus` role permission (root admin always), rendered from stored data with a
 * query count that does not grow with the number of servers.
 */
class PlayersOverviewTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Wednesday 2026-11-11 14:37 UTC. */
    private const NOW = '2026-11-11 14:37:00';

    private FakeConnector $connector;

    private ?Egg $minecraftEgg = null;

    private ?User $owner = null;

    private ?Node $node = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connector = new FakeConnector();
        $this->app->instance(Connector::class, $this->connector);
        Http::fake();
        app()->setLocale('en');
        // Otherwise the panel middleware sends every request to the installer.
        config()->set('app.installed', true);
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));

        (new PlayersPlusServiceProvider($this->app))->boot();
        $this->registerServerPageRoutes([PlayersPage::class]);
        $this->registerAdminPageRoutes();
        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** The panel skips plugins in tests: route the overview the way Filament does, middleware included. */
    private function registerAdminPageRoutes(): void
    {
        $panel = Filament::getPanel('admin');
        Route::middleware($panel->getMiddleware())
            ->name('filament.admin.')
            ->prefix($panel->getPath())
            ->group(function () use ($panel) {
                Route::middleware($panel->getAuthMiddleware())->group(fn () => PlayersOverviewPage::registerRoutes($panel));
            });
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * @param  list<string>  $permissions  null = root admin, [] = no role at all
     * @param  list<Node>  $nodes  limit the role to these nodes (empty = every node)
     */
    private function admin(?array $permissions = null, array $nodes = []): User
    {
        /** @var User $user */
        $user = User::factory()->create();
        if ($permissions === null) {
            $user->syncRoles(Role::getRootAdmin());
        } elseif ($permissions !== []) {
            /** @var Role $role */
            $role = Role::query()->create(['name' => 'Role ' . $user->id, 'guard_name' => Role::DEFAULT_GUARD_NAME]);
            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, Role::DEFAULT_GUARD_NAME));
            }
            $role->nodes()->sync(array_map(fn (Node $node) => $node->id, $nodes));
            $user->syncRoles($role);
        }
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    /** @param  list<string>  $tags */
    private function server(array $tags = ['minecraft'], string $name = '', ?Node $node = null): Server
    {
        if ($this->owner === null || $this->node === null) {
            /** @var User $owner */
            $owner = User::factory()->create();
            /** @var Node $node */
            $node = Node::factory()->create();
            [$this->owner, $this->node] = [$owner, $node];
        }

        $server = $this->createServerModel(['owner_id' => $this->owner->id, 'node_id' => ($node ?? $this->node)->id] + ($name === '' ? [] : ['name' => $name]));
        if ($tags === ['minecraft'] && $this->minecraftEgg !== null) {
            $egg = $this->minecraftEgg;
        } else {
            $egg = $this->cloneEggAndVariables($server->egg);
            $egg->forceFill(['tags' => $tags])->save();
            if ($tags === ['minecraft']) {
                $this->minecraftEgg = $egg;
            }
        }
        $server->forceFill(['egg_id' => $egg->id])->save();

        return $server->refresh();
    }

    private function sample(Server $server, string $at, SampleStatus $status, ?int $online = null): void
    {
        Sample::query()->create([
            'server_id' => $server->id,
            'polled_at' => CarbonImmutable::parse($at, 'UTC'),
            'status' => $status,
            'online' => $online,
            'max' => $status === SampleStatus::Online ? 20 : null,
        ]);
    }

    private function player(Server $server, string $name, int $lastSeenMinutesAgo): void
    {
        Player::query()->create([
            'server_id' => $server->id, 'name' => $name,
            'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()->subMinutes($lastSeenMinutesAgo),
        ]);
    }

    private function rollup(int $hours): void
    {
        $current = CarbonImmutable::now()->startOfHour();
        for ($i = $hours - 1; $i >= 0; $i--) {
            RollupCommand::rollupHour($current->subHours($i));
        }
    }

    /**
     * Alpha: online, 7/20 now, 24 h peak 14, average 11.0, 2 players this week.
     * Bravo: offline now, 24 h peak 4. Charlie: online with 9 at its last poll, but stale.
     * Echo is not Minecraft and must not be listed or counted.
     *
     * @return array{Server, Server, Server, Server}
     */
    private function seedServers(): array
    {
        $alpha = $this->server(name: 'Alpha');
        $bravo = $this->server(name: 'Bravo');
        $charlie = $this->server(name: 'Charlie');
        $echo = $this->server(['source'], 'Echo');

        $this->sample($alpha, '2026-11-11 10:00:00', SampleStatus::Online, 12);
        $this->sample($alpha, '2026-11-11 14:00:00', SampleStatus::Online, 14);
        $this->sample($alpha, '2026-11-11 14:36:00', SampleStatus::Online, 7);
        $this->sample($bravo, '2026-11-11 14:00:00', SampleStatus::Online, 4);
        $this->sample($bravo, '2026-11-11 14:36:00', SampleStatus::Offline);
        $this->sample($charlie, '2026-11-11 13:00:00', SampleStatus::Online, 9);
        $this->sample($echo, '2026-11-11 14:36:00', SampleStatus::Online, 50);
        $this->rollup(24);
        $this->player($alpha, 'Alice', 30);
        $this->player($alpha, 'Bob', 2 * 24 * 60);
        $this->player($alpha, 'Carol', 9 * 24 * 60);

        return [$alpha, $bravo, $charlie, $echo];
    }

    private function assertNothingContacted(): void
    {
        $this->assertSame([], $this->connector->opened, 'a page load must not query the game server');
        Http::assertNothingSent();
    }

    public function test_overview_lists_minecraft_servers_with_their_numbers_and_totals(): void
    {
        [$alpha, $bravo, $charlie, $echo] = $this->seedServers();
        $this->actingAs($this->admin());

        Livewire::test(PlayersOverviewPage::class)
            ->assertOk()
            ->loadTable()
            ->assertCanSeeTableRecords([$alpha, $bravo, $charlie])
            ->assertCanNotSeeTableRecords([$echo])
            ->assertTableColumnFormattedStateSet('online_now', '7 / 20', $alpha)
            ->assertTableColumnFormattedStateSet('peak_24h', '14', $alpha)
            // (12 + 14 + 7) / 3 online samples.
            ->assertTableColumnFormattedStateSet('average_7d', '11.0', $alpha)
            ->assertTableColumnFormattedStateSet('unique_7d', '2', $alpha)
            ->assertTableColumnFormattedStateSet('status', trans('players-plus::strings.status.online'), $alpha)
            ->assertTableColumnFormattedStateSet('status', trans('players-plus::strings.status.offline'), $bravo)
            ->assertTableColumnFormattedStateSet('online_now', trans('players-plus::strings.overview.none'), $bravo)
            ->assertTableColumnFormattedStateSet('peak_24h', '4', $bravo)
            ->assertTableColumnFormattedStateSet('status', trans('players-plus::strings.overview.stale'), $charlie)
            ->assertTableColumnFormattedStateSet('online_now', trans('players-plus::strings.overview.none'), $charlie)
            ->assertSee(PlayersPage::getUrl(panel: 'server', tenant: $alpha), false);

        // Live servers only: Alpha's 7. Bravo is offline, Charlie stale, Echo not Minecraft.
        $stats = $this->stats();
        $this->assertSame('7', $stats[trans('players-plus::strings.overview.total_online')]);
        $this->assertSame('1', $stats[trans('players-plus::strings.overview.servers_online')]);

        $chart = $this->chartData();
        $points = array_combine($chart['labels'], $chart['datasets'][0]['data']);
        $this->assertSame(18, $points['14:00'], 'Alpha 14 + Bravo 4');
        $this->assertSame(7, $points['14:36']);
        $this->assertNull($points['14:34']);
        $this->assertFalse($chart['datasets'][0]['spanGaps']);

        $this->assertNothingContacted();
    }

    public function test_server_without_data_shows_no_data(): void
    {
        $quiet = $this->server(name: 'Quiet');
        $this->actingAs($this->admin());

        Livewire::test(PlayersOverviewPage::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$quiet])
            ->assertTableColumnFormattedStateSet('status', trans('players-plus::strings.overview.no_data'), $quiet)
            ->assertTableColumnFormattedStateSet('peak_24h', trans('players-plus::strings.overview.none'), $quiet)
            ->assertTableColumnFormattedStateSet('unique_7d', '0', $quiet);
    }

    public function test_table_is_paginated_and_searchable(): void
    {
        $servers = [];
        for ($i = 1; $i <= 30; $i++) {
            $servers[] = $this->server(name: sprintf('Server %02d', $i));
        }
        $this->actingAs($this->admin());

        Livewire::test(PlayersOverviewPage::class)
            ->loadTable()
            ->assertCountTableRecords(30)
            ->assertCanSeeTableRecords(array_slice($servers, 0, 25))
            ->assertCanNotSeeTableRecords(array_slice($servers, 25))
            ->call('gotoPage', 2)
            ->assertCanSeeTableRecords(array_slice($servers, 25))
            ->searchTable('Server 07')
            ->assertCanSeeTableRecords([$servers[6]])
            ->assertCanNotSeeTableRecords([$servers[0]]);
    }

    /** The whole page (table, stats, chart) runs the same number of queries for 3 and 30 servers. */
    public function test_render_query_count_does_not_grow_with_server_count(): void
    {
        $this->actingAs($this->admin());

        $this->seedMany(3);
        // Warm-up: the first render also loads the user's roles and the permission cache.
        $this->renderQueries();
        $three = $this->renderQueries();
        $this->seedMany(27);
        $thirty = $this->renderQueries();

        $this->assertSame(count($three), count($thirty), "3 servers:\n" . implode("\n", $three) . "\n\n30 servers:\n" . implode("\n", $thirty));
        // Four per-server metrics, totals, chart, and the stale-poll check (last poll).
        $this->assertCount(7, array_filter($three, fn (string $query) => str_contains($query, '"pp_')), implode("\n", $three));
    }

    /** Widgets are Livewire components of their own: mounted directly they still check access. */
    public function test_widgets_refuse_admins_without_the_permission(): void
    {
        $this->seedServers();
        $this->actingAs($this->admin(['view health']));

        foreach ([PlayersOverviewStatsWidget::class, PlayersOverviewChartWidget::class] as $widget) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test($widget)->assertForbidden()->assertDontSee('Players online');
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $this->assertSame([], array_values(array_filter($queries, fn (string $query) => str_contains($query, '"pp_'))), "$widget read player data");
        }
    }

    /** Page 2, a search and a node-limited admin run the same queries as page 1 with 3 servers. */
    public function test_query_count_is_the_same_for_page_two_search_and_node_limited_admins(): void
    {
        $root = $this->admin();
        $this->actingAs($root);
        $this->seedMany(3);
        $this->renderQueries();
        $full = count($this->renderQueries());
        $update = count($this->updateQueries(fn ($page) => $page->call('gotoPage', 1)));

        $this->seedMany(27);
        $this->assertSame($update, count($this->updateQueries(fn ($page) => $page->call('gotoPage', 2))), 'page 2');
        // A name no other (random factory) server name can contain, so the search matches exactly one.
        $name = 'pp-search-target-'.bin2hex(random_bytes(6));
        Server::query()->orderByDesc('id')->firstOrFail()->forceFill(['name' => $name])->save();
        $this->assertSame($update, count($this->updateQueries(fn ($page) => $page->searchTable($name)->assertCountTableRecords(1))), 'search');

        /** @var Node $node */
        $node = $this->node;
        $this->actingAs($this->admin([PlayersOverviewPage::PERMISSION], [$node]));
        $this->renderQueries();
        $this->assertSame($full, count($this->renderQueries()), 'node-limited admin');
    }

    /**
     * Queries of one Livewire update (not the first render) on an already rendered page.
     *
     * @param  callable(mixed): mixed  $action
     * @return list<string>
     */
    private function updateQueries(callable $action): array
    {
        $page = Livewire::test(PlayersOverviewPage::class)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action($page);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function seedMany(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $server = $this->server();
            $this->sample($server, '2026-11-11 14:30:00', SampleStatus::Online, 5);
            $this->sample($server, '2026-11-11 14:36:00', SampleStatus::Online, 2);
            $this->player($server, "P$i", 10);
        }
        $this->rollup(2);
    }

    /** @return list<string> */
    private function renderQueries(): array
    {
        user()?->refresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        // One full render: table page (count + rows), the four per-server metrics, totals, chart, poll health.
        Livewire::test(PlayersOverviewPage::class)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    /** Admins limited to some nodes see only those nodes' servers, in the table, totals and chart. */
    public function test_node_limited_admin_sees_only_their_nodes(): void
    {
        [$alpha, $bravo, $charlie, $echo] = $this->seedServers();
        /** @var Node $otherNode */
        $otherNode = Node::factory()->create();
        $delta = $this->server(name: 'Delta', node: $otherNode);
        $this->sample($delta, '2026-11-11 14:36:00', SampleStatus::Online, 40);
        $this->sample($delta, '2026-11-11 14:00:00', SampleStatus::Online, 30);

        // Root admin: every node. Alpha 7 + Delta 40 live; Alpha 14 + Bravo 4 + Delta 30 at 14:00.
        $this->actingAs($this->admin());
        Livewire::test(PlayersOverviewPage::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$alpha, $bravo, $charlie, $delta]);
        $this->assertSame('47', $this->stats()[trans('players-plus::strings.overview.total_online')]);
        $this->assertSame('2', $this->stats()[trans('players-plus::strings.overview.servers_online')]);
        $points = array_combine(($chart = $this->chartData())['labels'], $chart['datasets'][0]['data']);
        $this->assertSame([48, 47], [$points['14:00'], $points['14:36']]);

        // Limited to the other node: Delta only.
        $this->actingAs($this->admin([PlayersOverviewPage::PERMISSION], [$otherNode]));
        Livewire::test(PlayersOverviewPage::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$delta])
            ->assertCanNotSeeTableRecords([$alpha, $bravo, $charlie, $echo])
            ->assertDontSee('Alpha');
        $this->assertSame('40', $this->stats()[trans('players-plus::strings.overview.total_online')]);
        $this->assertSame('1', $this->stats()[trans('players-plus::strings.overview.servers_online')]);
        $points = array_combine(($chart = $this->chartData())['labels'], $chart['datasets'][0]['data']);
        $this->assertSame([30, 40], [$points['14:00'], $points['14:36']]);

        // Limited to the first node: Delta is gone.
        /** @var Node $firstNode */
        $firstNode = $this->node;
        $this->actingAs($this->admin([PlayersOverviewPage::PERMISSION], [$firstNode]));
        Livewire::test(PlayersOverviewPage::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$alpha, $bravo, $charlie])
            ->assertCanNotSeeTableRecords([$delta]);
        $this->assertSame('7', $this->stats()[trans('players-plus::strings.overview.total_online')]);
    }

    public function test_root_admin_and_permitted_admin_can_open_it(): void
    {
        $this->seedServers();

        foreach ([$this->admin(), $this->admin([PlayersOverviewPage::PERMISSION])] as $user) {
            $this->actingAs($user);
            $this->assertTrue(PlayersOverviewPage::canAccess());
            $this->get(PlayersOverviewPage::getUrl(panel: 'admin'))->assertOk()->assertSee('Alpha');
            $this->assertContains(PlayersOverviewPage::getUrl(panel: 'admin'), $this->navigationUrls());
        }
    }

    public function test_admin_without_the_permission_gets_403_and_no_navigation_item(): void
    {
        $this->seedServers();
        $this->actingAs($this->admin(['view health']));

        $this->assertFalse(PlayersOverviewPage::canAccess());
        $this->get(PlayersOverviewPage::getUrl(panel: 'admin'))->assertForbidden();
        Livewire::test(PlayersOverviewPage::class)->assertForbidden();
        $this->assertNotContains(PlayersOverviewPage::getUrl(panel: 'admin'), $this->navigationUrls());
        $this->assertFalse(PlayersOverviewStatsWidget::canView());
        $this->assertFalse(PlayersOverviewChartWidget::canView());
    }

    public function test_non_admin_users_cannot_reach_it(): void
    {
        [$alpha] = $this->seedServers();
        // The server's own owner, without any admin role.
        $this->actingAs($alpha->user);

        $this->assertFalse(PlayersOverviewPage::canAccess());
        $this->get(PlayersOverviewPage::getUrl(panel: 'admin'))->assertForbidden();
        Livewire::test(PlayersOverviewPage::class)->assertForbidden();
        $this->assertNotContains(PlayersOverviewPage::getUrl(panel: 'admin'), $this->navigationUrls());
    }

    public function test_it_is_registered_on_the_admin_panel_only(): void
    {
        $plugin = new PlayersPlusPlugin();
        $plugin->register(Filament::getPanel('admin'));
        $plugin->register(Filament::getPanel('server'));

        $this->assertContains(PlayersOverviewPage::class, Filament::getPanel('admin')->getPages());
        $this->assertNotContains(PlayersOverviewPage::class, Filament::getPanel('server')->getPages());

        // Even a root admin can't open it inside the server panel.
        [$alpha] = $this->seedServers();
        $this->actingAs($this->admin());
        Filament::setCurrentPanel('server');
        Filament::setTenant($alpha);
        $this->assertFalse(PlayersOverviewPage::canAccess());
        Livewire::test(PlayersOverviewPage::class)->assertForbidden();
    }

    public function test_permission_is_registered_for_the_role_editor(): void
    {
        $this->assertSame('view players-plus', PlayersOverviewPage::PERMISSION);
        $this->assertSame(['view'], Role::getPermissionList()['players-plus']);
    }

    /** @return list<string> */
    private function navigationUrls(): array
    {
        $panel = Filament::getPanel('admin');
        $before = count($panel->getNavigationItems());
        PlayersOverviewPage::registerNavigationItems();
        $added = array_slice($panel->getNavigationItems(), $before);

        return array_values(array_map(fn (NavigationItem $item) => (string) $item->getUrl(), $added));
    }

    /** @return array<string, string> label => value */
    private function stats(): array
    {
        $widget = Livewire::test(PlayersOverviewStatsWidget::class)->instance();
        $stats = [];
        foreach ((new ReflectionMethod($widget, 'getStats'))->invoke($widget) as $stat) {
            $stats[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function chartData(): array
    {
        $widget = Livewire::test(PlayersOverviewChartWidget::class)->instance();

        return (new ReflectionMethod($widget, 'getData'))->invoke($widget);
    }
}
