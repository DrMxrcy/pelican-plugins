<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Models\Egg;
use App\Models\Mount;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PrepareStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;

/**
 * Plan 30 step 5: the Modpacks page. Viewing needs file.read; installing and cancelling
 * need settings.reinstall + file.create + file.delete + backup.create, enforced inside
 * the Livewire handlers themselves (direct calls), not only by hidden buttons.
 */
class ModpacksPageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const INSTALL_PERMISSIONS = [
        SubuserPermission::SettingsReinstall,
        SubuserPermission::FileCreate,
        SubuserPermission::FileDelete,
        SubuserPermission::BackupCreate,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Filament::setCurrentPanel('server');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /**
     * @param  array<int, SubuserPermission>  $permissions  empty = the owner
     * @param  array<int, string>  $tags
     */
    private function actingOn(array $permissions = [], array $tags = ['minecraft', 'fabric']): Server
    {
        [$user, $server] = $this->scenarioServer($permissions, $tags);
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    /**
     * Modrinth answers for the version (for $loader/$minecraft) and its project.
     *
     * @param  array<string, mixed>  $extra  stubs replacing (same key) or adding to the defaults
     */
    private function fakeModrinthApi(string $loader = 'fabric', string $minecraft = '1.20.1', array $extra = []): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $version = Mrpack::version($archive);
        $version['loaders'] = [$loader];
        $version['game_versions'] = [$minecraft];

        Http::swap(new HttpFactory());
        Http::fake(array_merge([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response($version),
            'api.modrinth.com/v2/project/'.Mrpack::PACK_ID => Http::response([
                'id' => Mrpack::PACK_ID,
                'slug' => 'test-pack',
                'title' => 'Test <b>Pack</b>',
                'project_type' => 'modpack',
                'description' => 'desc',
                'body' => "# Hi\n<script>alert(1)</script>\n[x](javascript:alert(1))",
                'icon_url' => 'https://cdn.modrinth.com/data/PACKID01/icon.png',
                'gallery' => [],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([['name' => 'optimization', 'project_type' => 'modpack', 'header' => 'categories']]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
        ], $extra));
    }

    public function test_subuser_without_file_read_cannot_open_the_page(): void
    {
        $this->actingOn([SubuserPermission::ControlConsole]);

        $this->assertFalse(ModpacksPage::canAccess());
        Livewire::test(ModpacksPage::class)->assertForbidden();
    }

    public function test_page_is_hidden_on_non_minecraft_servers(): void
    {
        $this->actingOn([], ['proxy']);

        $this->assertFalse(ModpacksPage::canAccess());
    }

    /**
     * Binds an engine that fails the test if anything reaches it. (A 403 makes the panel's
     * exception handler roll the test's DB transaction back, so "no row was written" can't
     * be read from the database afterwards; the engine is the only way to start or cancel.)
     */
    private function forbidEngine(): void
    {
        $engine = Mockery::mock(InstallEngine::class);
        $engine->shouldNotReceive('start');
        $engine->shouldNotReceive('requestCancel');
        $this->app->instance(InstallEngine::class, $engine);
    }

    public function test_viewer_with_file_read_only_gets_403_on_direct_install_and_cancel(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead]);
        $this->fakeModrinthApi();
        $this->runningInstall($server);
        $this->forbidEngine();

        $this->assertTrue(ModpacksPage::canAccess());
        $this->assertFalse(ModpacksPage::canInstall());

        Livewire::test(ModpacksPage::class)->assertOk()->call('cancelInstall')->assertForbidden();
        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/version/'));
    }

    /** @return array<string, array{SubuserPermission}> */
    public static function eachInstallPermission(): array
    {
        $cases = [];
        foreach (self::INSTALL_PERMISSIONS as $permission) {
            $cases['missing '.$permission->value] = [$permission];
        }

        return $cases;
    }

    #[DataProvider('eachInstallPermission')]
    public function test_lacking_any_install_permission_gets_403_on_direct_install_and_cancel(SubuserPermission $missing): void
    {
        $granted = array_values(array_filter(self::INSTALL_PERMISSIONS, fn (SubuserPermission $permission) => $permission !== $missing));
        $server = $this->actingOn([SubuserPermission::FileRead, ...$granted]);
        $this->fakeModrinthApi();
        $this->runningInstall($server);
        $this->forbidEngine();

        $this->assertTrue(ModpacksPage::canAccess());
        $this->assertFalse(ModpacksPage::canInstall());

        Livewire::test(ModpacksPage::class)->call('cancelInstall')->assertForbidden();
        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/version/'));
    }

    public function test_subuser_with_every_install_permission_can_install_and_cancel(): void
    {
        $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->fakeModrinthApi();

        $this->assertTrue(ModpacksPage::canInstall());

        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertOk();

        $install = PackInstall::query()->firstOrFail();
        $this->assertSame(Mrpack::PACK_ID, $install->pack_id);
        $this->assertSame(Mrpack::VERSION_ID, $install->version_id);
        $this->assertSame('Test <b>Pack</b>', $install->name);
        Queue::assertPushed(PrepareStep::class);

        Livewire::test(ModpacksPage::class)->call('cancelInstall')->assertOk();
        $this->assertNotNull($install->refresh()->cancel_requested_at);
    }

    public function test_owner_and_root_admin_can_install(): void
    {
        $server = $this->actingOn();
        $this->assertTrue(ModpacksPage::canInstall());

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        Filament::setTenant($server);

        $this->assertTrue(ModpacksPage::canAccess());
        $this->assertTrue(ModpacksPage::canInstall());
    }

    /** @return array<string, array{string, string}> */
    public static function otherTargets(): array
    {
        return [
            'other loader' => ['forge', '1.20.1'],
            'other minecraft version' => ['fabric', '1.21.1'],
        ];
    }

    #[DataProvider('otherTargets')]
    public function test_version_for_another_loader_or_minecraft_version_cannot_be_installed(string $loader, string $minecraft): void
    {
        $this->actingOn();
        $this->fakeModrinthApi($loader, $minecraft);

        Livewire::test(ModpacksPage::class)
            ->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)
            ->assertNotified(trans('modpacks-plus::strings.install.not_matching'));

        $this->assertSame(0, PackInstall::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_version_of_another_pack_is_refused(): void
    {
        $this->actingOn();
        $this->fakeModrinthApi();

        Livewire::test(ModpacksPage::class)->call('installPack', 'OTHERPCK', Mrpack::VERSION_ID)->assertNotFound();
        Livewire::test(ModpacksPage::class)->call('installPack', '../x', Mrpack::VERSION_ID)->assertNotFound();

        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_second_install_while_one_runs_is_refused(): void
    {
        $server = $this->actingOn();
        $this->fakeModrinthApi();
        $this->runningInstall($server);

        Livewire::test(ModpacksPage::class)
            ->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertSame(1, PackInstall::query()->count());
    }

    public function test_progress_banner_and_needs_label_render_escaped(): void
    {
        $server = $this->actingOn();
        $this->fakeModrinthApi();

        $install = $this->runningInstall($server);
        $install->forceFill(['name' => '<script>alert(1)</script>'])->save();
        $install->addLog('info', 'moved_aside', ['path' => '<img src=x onerror=alert(1)>', 'to' => 'x']);
        $install->save();

        Livewire::test(ModpacksPage::class)
            ->assertOk()
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('<img src=x onerror')
            ->assertSee(trans('modpacks-plus::strings.steps.backup'));

        app(InstallEngine::class)->complete($install);

        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.page.installed_pack', ['name' => '<script>alert(1)</script>', 'version' => '1.0.0']));
    }

    /** Modrinth answers with two versions: one matching the server, one needing NeoForge 1.21.1. */
    private function fakeTwoVersions(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $match = Mrpack::version($archive);
        $other = Mrpack::version($archive);
        $other['id'] = 'VERSID02';
        $other['version_number'] = '2.0.0';
        $other['loaders'] = ['neoforge'];
        $other['game_versions'] = ['1.21.1'];

        $this->fakeModrinthApi(extra: [
            'api.modrinth.com/v2/project/'.Mrpack::PACK_ID.'/version' => Http::response([$match, $other]),
        ]);
    }

    /**
     * The detail view's rendered description HTML and its visible version actions (name => label).
     *
     * @return array{string, array<string, string>}
     */
    private function detailView(): array
    {
        /** @var ModpacksPage $instance */
        $instance = Livewire::test(ModpacksPage::class)->instance();
        $components = (new \ReflectionMethod($instance, 'detailSchema'))->invoke($instance, Mrpack::PACK_ID);
        // Attach to a schema so closures and child schemas can be evaluated as on the page.
        $schema = Schema::make($instance)->components($components);

        $html = '';
        $labels = [];
        $walk = function (array $components) use (&$walk, &$html, &$labels): void {
            foreach ($components as $component) {
                if ($component instanceof Html) {
                    $html .= (string) $component->getContent();
                }
                if ($component instanceof Section) {
                    foreach ($component->getHeaderActions() as $action) {
                        if ($action->isVisible()) {
                            $labels[$action->getName()] = (string) $action->getLabel();
                        }
                    }
                    $walk($component->getChildSchema()?->getComponents(withHidden: true) ?? []);
                }
            }
        };
        $walk($schema->getComponents(withHidden: true));

        return [$html, $labels];
    }

    public function test_detail_view_marks_mismatching_versions_and_strips_html(): void
    {
        $this->actingOn();
        $this->fakeTwoVersions();

        [$html, $labels] = $this->detailView();

        $this->assertStringContainsString('<h1>Hi</h1>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame(trans('modpacks-plus::strings.actions.install'), $labels['install_0'] ?? null);
        // The owner may install but not switch loaders (setting off).
        $this->assertSame(trans('modpacks-plus::strings.install.ask_admin', ['target' => 'NeoForge 1.21.1']), $labels['needs_1'] ?? null);
    }

    public function test_install_and_cancel_buttons_are_hidden_without_install_permissions(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, SubuserPermission::FileCreate, SubuserPermission::FileDelete, SubuserPermission::BackupCreate]);
        $this->fakeTwoVersions();

        [, $labels] = $this->detailView();
        $this->assertArrayNotHasKey('install_0', $labels);
        $this->assertArrayHasKey('needs_1', $labels);

        $this->runningInstall($server);
        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.steps.backup'))
            ->assertDontSee(trans('modpacks-plus::strings.actions.cancel'));
    }

    public function test_install_and_cancel_buttons_are_shown_with_install_permissions(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->fakeTwoVersions();

        [, $labels] = $this->detailView();
        $this->assertArrayHasKey('install_0', $labels);

        $this->runningInstall($server);
        Livewire::test(ModpacksPage::class)->assertSee(trans('modpacks-plus::strings.actions.cancel'));
    }

    private function runningInstall(Server $server): PackInstall
    {
        return app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Running Pack', '1.0.0');
    }

    /** A switch that failed after setting the world aside. */
    private function failedSwitch(Server $server): PackInstall
    {
        $install = $this->runningInstall($server);
        $install->forceFill([
            'status' => PackInstall::STATUS_FAILED,
            'error' => 'step_timeout',
            'preserved_path' => '.modpacks-plus/preserved-x-'.$install->id,
            'progress' => ['preserved' => ['world', 'ops.json'], 'worlds' => ['world']],
        ])->save();

        return $install;
    }

    #[DataProvider('eachInstallPermission')]
    public function test_restore_previous_files_needs_every_install_permission(SubuserPermission $missing): void
    {
        $granted = array_values(array_filter(self::INSTALL_PERMISSIONS, fn (SubuserPermission $permission) => $permission !== $missing));
        $server = $this->actingOn([SubuserPermission::FileRead, ...$granted]);
        $this->failedSwitch($server);

        Livewire::test(ModpacksPage::class)->call('restorePreviousFiles')->assertForbidden();

        Queue::assertNotPushed(RestorePreviousFilesJob::class);
    }

    public function test_restore_previous_files_queues_one_restore_of_the_latest_install(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $install = $this->failedSwitch($server);

        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.actions.restore'))
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.started'));

        Queue::assertPushed(RestorePreviousFilesJob::class, fn (RestorePreviousFilesJob $job) => $job->installId === $install->id);
        $this->assertNotNull($install->refresh()->restore_requested_at);

        // A second click while it runs starts nothing.
        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.restore'))
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.nothing'));
        Queue::assertPushed(RestorePreviousFilesJob::class, 1);
    }

    public function test_nothing_to_restore_after_a_successful_install(): void
    {
        $server = $this->actingOn();
        $install = $this->failedSwitch($server);
        // A switch that installed has put its files back.
        $install->forceFill(['status' => PackInstall::STATUS_INSTALLED, 'error' => null, 'progress' => array_merge($install->progress ?? [], ['preserved_restored' => true])])->save();

        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.restore'))
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.nothing'));

        Queue::assertNotPushed(RestorePreviousFilesJob::class);
    }

    private function importNeoForgeEgg(string $name = 'NeoForge'): Egg
    {
        return LoaderEggResolverTest::makeEgg([
            'uuid' => LoaderEggResolverTest::NEOFORGE_UUID,
            'name' => $name,
            'startup_commands' => ['Default' => 'java @unix_args.txt'],
        ]);
    }

    private function actingAsRootAdminOn(Server $server): void
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        Filament::setTenant($server);
    }

    private function actingAsAdminWith(Server $server, string $permission): void
    {
        /** @var Role $role */
        $role = Role::factory()->create(['name' => 'Admin '.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles($role);
        $this->actingAs($admin);
        Filament::setTenant($server);
    }

    /** The engine must never be reached (403s roll the test transaction back, so the DB can't tell). */
    private function forbidSwitch(): void
    {
        $engine = Mockery::mock(InstallEngine::class);
        $engine->shouldNotReceive('start');
        $this->app->instance(InstallEngine::class, $engine);
    }

    public function test_owner_sees_ask_an_admin_and_a_direct_switch_call_is_403_with_the_setting_off(): void
    {
        $this->actingOn();
        $this->importNeoForgeEgg();
        $this->fakeTwoVersions();

        $this->assertTrue(ModpacksPage::canInstall());
        $this->assertFalse(ModpacksPage::canSwitchLoader());
        [, $labels] = $this->detailView();
        $this->assertSame(trans('modpacks-plus::strings.install.ask_admin', ['target' => 'NeoForge 1.21.1']), $labels['needs_1'] ?? null);
        $this->assertArrayNotHasKey('switch_1', $labels);

        $this->fakeModrinthApi('neoforge', '1.21.1');
        $this->forbidSwitch();
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/version/'));
    }

    public function test_with_the_setting_on_the_owner_can_switch(): void
    {
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $this->actingOn();
        $egg = $this->importNeoForgeEgg();
        $this->fakeTwoVersions();

        $this->assertTrue(ModpacksPage::canSwitchLoader());
        [, $labels] = $this->detailView();
        $this->assertSame(trans('modpacks-plus::strings.install.switch', ['target' => 'NeoForge 1.21.1']), $labels['switch_1'] ?? null);

        $this->fakeModrinthApi('neoforge', '1.21.1');
        Livewire::test(ModpacksPage::class)
            ->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'Test <b>Pack</b>']));

        $install = PackInstall::query()->firstOrFail();
        $this->assertSame(['loader' => 'neoforge', 'minecraft' => '1.21.1', 'egg_id' => $egg->id, 'egg_name' => 'NeoForge'], $install->loader_switch);
        $this->assertSame(InstallEngine::SWITCH_ORDER, array_column($install->steps ?? [], 'key'));
        Queue::assertPushed(PrepareStep::class);
    }

    public function test_root_admin_and_update_server_admins_can_switch_with_the_setting_off(): void
    {
        $server = $this->actingOn();
        $this->importNeoForgeEgg();
        $this->fakeModrinthApi('neoforge', '1.21.1');

        $this->actingAsRootAdminOn($server);
        $this->assertTrue(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertOk();
        $this->assertSame(1, PackInstall::query()->whereNotNull('loader_switch')->count());

        PackInstall::query()->delete();
        $this->actingAsAdminWith($server, 'update server');
        $this->assertTrue(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertOk();
        $this->assertSame(1, PackInstall::query()->whereNotNull('loader_switch')->count());
    }

    public function test_an_admin_with_only_view_server_cannot_open_the_page(): void
    {
        $server = $this->actingOn();
        $this->actingAsAdminWith($server, 'view server');

        $this->assertFalse(ModpacksPage::canAccess());
        $this->assertFalse(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->assertForbidden();
    }

    public function test_an_admin_without_update_server_cannot_switch_even_with_the_install_permissions(): void
    {
        $server = $this->actingOn();
        $this->importNeoForgeEgg();
        $this->fakeModrinthApi('neoforge', '1.21.1');
        $this->actingAsAdminWith($server, 'view server');
        Subuser::query()->create(['user_id' => user()?->id, 'server_id' => $server->id, 'permissions' => array_map(fn (SubuserPermission $permission) => $permission->value, [SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS])]);
        Filament::setTenant($server->fresh());
        $this->forbidSwitch();

        $this->assertTrue(ModpacksPage::canInstall());
        $this->assertFalse(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();
    }

    public function test_subuser_with_the_install_permissions_switches_only_when_the_setting_is_on(): void
    {
        $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->importNeoForgeEgg();
        $this->fakeModrinthApi('neoforge', '1.21.1');

        $this->assertFalse(ModpacksPage::canSwitchLoader());

        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $this->assertTrue(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertOk();
        $this->assertSame(1, PackInstall::query()->whereNotNull('loader_switch')->count());
    }

    public function test_subuser_with_the_setting_off_gets_403_on_a_direct_switch_call(): void
    {
        $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->importNeoForgeEgg();
        $this->fakeModrinthApi('neoforge', '1.21.1');
        $this->forbidSwitch();

        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();
    }

    #[DataProvider('eachInstallPermission')]
    public function test_the_setting_never_lets_a_subuser_without_every_install_permission_switch(SubuserPermission $missing): void
    {
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $granted = array_values(array_filter(self::INSTALL_PERMISSIONS, fn (SubuserPermission $permission) => $permission !== $missing));
        $this->actingOn([SubuserPermission::FileRead, ...$granted]);
        $this->importNeoForgeEgg();
        $this->fakeModrinthApi('neoforge', '1.21.1');
        $this->forbidSwitch();

        $this->assertFalse(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertForbidden();
    }

    public function test_with_no_matching_egg_nothing_changes_and_the_message_names_the_egg(): void
    {
        $server = $this->actingOn();
        $this->fakeModrinthApi('neoforge', '1.21.1');
        $this->actingAsRootAdminOn($server);
        $eggBefore = $server->fresh()?->egg_id;

        Livewire::test(ModpacksPage::class)
            ->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID)
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertSame(0, PackInstall::query()->count());
        $this->assertSame($eggBefore, $server->fresh()?->egg_id);
        Queue::assertNothingPushed();

        /** @var ModpacksPage $page */
        $page = Livewire::test(ModpacksPage::class)->instance();
        $text = (string) (new \ReflectionMethod($page, 'switchDescription'))->invoke($page, ['loader' => 'neoforge', 'minecraft' => '1.21.1']);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.reasons.egg_missing', ['detail' => 'NeoForge'])), $text);
        $this->assertStringContainsString('NeoForge', $text);
    }

    public function test_switch_confirm_lists_exactly_what_resets_escaped(): void
    {
        $server = $this->actingOn();
        $this->importNeoForgeEgg('Neo<b>Forge</b>');
        $mount = Mount::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'shared-maps', 'source' => '/srv/maps', 'target' => '/maps']);
        $mount->eggs()->attach($server->egg_id);
        $server->mounts()->attach($mount->id);
        $open = Mount::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'open-mount', 'source' => '/srv/open', 'target' => '/open']);
        $server->mounts()->attach($open->id);
        $server->forceFill(['startup' => 'java -jar <fabric>.jar', 'image' => 'ghcr.io/pelican-eggs/yolks:java_17'])->save();
        $this->actingAsRootAdminOn($server);

        /** @var ModpacksPage $page */
        $page = Livewire::test(ModpacksPage::class)->instance();
        $html = (string) (new \ReflectionMethod($page, 'switchDescription'))->invoke($page, ['loader' => 'neoforge', 'minecraft' => '1.21.1']);

        $this->assertStringContainsString(e(trans('modpacks-plus::strings.install.reset_startup', ['from' => 'java -jar <fabric>.jar', 'to' => 'java @unix_args.txt'])), $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.install.reset_image', ['from' => 'ghcr.io/pelican-eggs/yolks:java_17', 'to' => 'ghcr.io/pelican-eggs/yolks:java_21'])), $html);
        $this->assertStringContainsString('MC_VERSION, NEOFORGE_VERSION', $html);
        $this->assertStringContainsString('MINECRAFT_VERSION', $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.install.reset_mounts', ['mounts' => 'shared-maps'])), $html);
        $this->assertStringNotContainsString('open-mount', $html);
        $this->assertStringContainsString('Neo&lt;b&gt;Forge&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Forge</b>', $html);
        $this->assertStringNotContainsString('<fabric>', $html);
    }

    /** A failed switch whose record holds a startup command, an image and variable values. */
    private function failedSwitchWithSetup(Server $server): PackInstall
    {
        $install = $this->runningInstall($server);
        $install->forceFill([
            'loader_switch' => ['loader' => 'neoforge', 'minecraft' => '1.21.1', 'egg_id' => 1, 'egg_name' => 'NeoForge', 'image' => 'ghcr.io/pelican-eggs/yolks:java_21'],
            'snapshot' => [
                'egg_id' => $server->egg_id, 'egg_uuid' => 'x', 'egg_name' => 'Old Fabric',
                'startup' => 'java -Dsecret-startup-flag -jar server.jar',
                'image' => 'ghcr.io/private/registry:fabric',
                'variables' => ['MINECRAFT_VERSION' => 'viewable-value-1.20.1'],
                'hidden_variables' => ['RCON_PASSWORD'],
                'mounts' => [],
            ],
            'preserved_path' => '.modpacks-plus/preserved-x-'.$install->id,
            'progress' => ['preserved' => ['world'], 'worlds' => ['world'], 'egg_changed' => true],
        ])->save();
        app(InstallEngine::class)->fail($install, 'step_timeout', 'await_reinstall');

        return $install->refresh();
    }

    public function test_file_read_viewer_never_sees_the_previous_startup_image_or_variable_values(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead]);
        $this->failedSwitchWithSetup($server);

        Livewire::test(ModpacksPage::class)
            ->assertOk()
            ->assertSee('Old Fabric')
            ->assertSee(trans('modpacks-plus::strings.summary.previous_hidden', ['egg' => 'Old Fabric', 'count' => 2]))
            ->assertDontSee('MINECRAFT_VERSION')
            ->assertDontSee('RCON_PASSWORD')
            ->assertDontSee('secret-startup-flag')
            ->assertDontSee('ghcr.io/private/registry:fabric')
            ->assertDontSee('ghcr.io/pelican-eggs/yolks:java_21')
            ->assertDontSee('viewable-value-1.20.1');
    }

    public function test_startup_read_viewer_sees_the_previous_setup_but_no_hidden_value(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, SubuserPermission::StartupRead]);
        $this->failedSwitchWithSetup($server);

        Livewire::test(ModpacksPage::class)
            ->assertSee('secret-startup-flag')
            ->assertSee('ghcr.io/private/registry:fabric')
            ->assertSee('MINECRAFT_VERSION=viewable-value-1.20.1')
            ->assertSee('RCON_PASSWORD')
            ->assertDontSee('RCON_PASSWORD=');
    }

    public function test_switch_confirm_hides_startup_and_image_without_startup_read(): void
    {
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->importNeoForgeEgg();
        $server->forceFill(['startup' => 'java -Dsecret-startup-flag -jar x.jar', 'image' => 'ghcr.io/private/registry:fabric'])->save();
        Filament::setTenant($server->fresh());

        /** @var ModpacksPage $page */
        $page = Livewire::test(ModpacksPage::class)->instance();
        $html = (string) (new \ReflectionMethod($page, 'switchDescription'))->invoke($page, ['loader' => 'neoforge', 'minecraft' => '1.21.1']);

        $this->assertStringNotContainsString('secret-startup-flag', $html);
        $this->assertStringNotContainsString('ghcr.io/private/registry', $html);
        $this->assertStringNotContainsString('java @unix_args.txt', $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.install.reset_startup_hidden')), $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.install.reset_image_hidden')), $html);
    }

    public function test_page_and_restore_stay_reachable_after_a_failed_reinstall(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $install = $this->failedSwitch($server);
        Server::query()->whereKey($server->id)->update(['status' => ServerState::ReinstallFailed->value, 'installed_at' => now()]);
        Filament::setTenant($server->fresh());

        $this->assertTrue($server->fresh()?->isInConflictState());
        $this->assertTrue(ModpacksPage::canAccess());
        Livewire::test(ModpacksPage::class)
            ->assertOk()
            ->assertSee(trans('modpacks-plus::strings.actions.restore'))
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.started'));

        Queue::assertPushed(RestorePreviousFilesJob::class, fn (RestorePreviousFilesJob $job) => $job->installId === $install->id);
    }

    public function test_restore_while_a_reinstall_may_still_run_is_queued_to_wait(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $install = $this->failedSwitch($server);
        $install->setProgress('reinstall_requested_at', now()->subMinutes(2)->toIso8601String());
        $install->save();

        Livewire::test(ModpacksPage::class)
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.pending'));

        Queue::assertPushed(RestorePreviousFilesJob::class, 1);
        $this->assertNotNull($install->refresh()->restore_requested_at);
    }

    public function test_switch_confirm_counts_variables_without_startup_read(): void
    {
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $this->importNeoForgeEgg();

        /** @var ModpacksPage $page */
        $page = Livewire::test(ModpacksPage::class)->instance();
        $html = (string) (new \ReflectionMethod($page, 'switchDescription'))->invoke($page, ['loader' => 'neoforge', 'minecraft' => '1.21.1']);

        $this->assertStringNotContainsString('MINECRAFT_VERSION', $html);
        $this->assertMatchesRegularExpression('/\d+ egg settings reset/', $html);
    }

    public function test_discard_pending_restore_unblocks_installs_and_moves_nothing(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        $install = $this->failedSwitch($server);
        $this->fakeModrinthApi();

        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.actions.discard_restore'))
            ->call('discardPendingRestore')
            ->assertNotified(trans('modpacks-plus::strings.restore.discarded', ['path' => (string) $install->preserved_path]));

        $install->refresh();
        $this->assertNotNull($install->restored_at);
        $this->assertTrue((bool) $install->progressValue('restore_discarded'));
        $this->assertFalse($install->hasPreservedPending());
        $this->assertSame(0, $this->files->writeCalls());
        Queue::assertNotPushed(RestorePreviousFilesJob::class);

        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.discard_restore'))
            ->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'Test <b>Pack</b>']));
    }

    public function test_discard_pending_restore_needs_the_install_permissions(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead, SubuserPermission::FileCreate, SubuserPermission::FileDelete, SubuserPermission::BackupCreate]);
        $this->failedSwitch($server);

        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.discard_restore'))
            ->call('discardPendingRestore')
            ->assertForbidden();
    }
}
