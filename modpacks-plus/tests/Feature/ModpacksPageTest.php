<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Role;
use App\Models\Server;
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
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->assertSame(trans('modpacks-plus::strings.install.needs', ['target' => 'Neoforge 1.21.1']), $labels['needs_1'] ?? null);
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
        $install->forceFill(['status' => PackInstall::STATUS_INSTALLED, 'error' => null])->save();

        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.restore'))
            ->call('restorePreviousFiles')
            ->assertNotified(trans('modpacks-plus::strings.restore.nothing'));

        Queue::assertNotPushed(RestorePreviousFilesJob::class);
    }
}
