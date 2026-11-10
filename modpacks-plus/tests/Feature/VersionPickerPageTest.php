<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Jobs\Steps\VersionPrepareStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\LoaderVersions;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\LoaderMeta;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 40 step 3: the "Minecraft version" picker on the Modpacks page. Who sees and may use
 * it (as for a loader switch), lists fetched only once the form is open, the choice checked
 * again on the server against the lists, outage message, only loaders with a usable egg.
 */
class VersionPickerPageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private Egg $fabric;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Filament::setCurrentPanel('server');
        Cache::flush();
        $this->fabric = VersionChangeTest::makeFabricEgg();
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function fakeMeta(array $overrides = []): void
    {
        // Anything else (the pack table's search) gets nothing useful.
        Http::fake(array_merge(LoaderMeta::stubs(), $overrides, ['*' => Http::response([], 404)]));
    }

    private function ownerOnFabricServer(): Server
    {
        [$user, $server] = $this->scenarioServer();
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    private function actingAsRootAdminOn(Server $server): void
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        Filament::setTenant($server);
    }

    private function forbidEngine(): void
    {
        $engine = Mockery::mock(InstallEngine::class);
        $engine->shouldNotReceive('start');
        $engine->shouldNotReceive('startVersionChange');
        $this->app->instance(InstallEngine::class, $engine);
    }

    private function assertNoMetadataRequest(): void
    {
        Http::assertNotSent(fn (Request $request) => LoaderMeta::isMetadataRequest($request->url()));
    }

    public function test_the_page_load_never_contacts_the_metadata_hosts_and_opening_the_form_does(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta();

        $page = Livewire::test(ModpacksPage::class)
            ->assertOk()
            ->assertActionVisible('minecraft_version');
        $this->assertNoMetadataRequest();

        $page->mountAction('minecraft_version')->assertOk()
            ->assertFormFieldExists('loader', fn (Select $field) => $field->getOptions() === ['fabric' => 'Fabric'] && $field->getState() === 'fabric')
            ->assertFormFieldExists('minecraft', fn (Select $field) => array_keys($field->getOptions()) === ['26.3', '26.2', '26.1.2', '1.21.1', '1.20.1']);
        Http::assertSent(fn (Request $request) => $request->url() === LoaderVersions::FABRIC_GAME_URL);
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (LoaderMeta::isMetadataRequest($request->url())) {
                $this->assertContains($request->url(), LoaderVersions::URLS);
            }
        }
    }

    public function test_an_owner_without_the_switch_setting_sees_no_action_and_a_direct_call_is_403(): void
    {
        $this->ownerOnFabricServer();
        $this->fakeMeta();
        $this->forbidEngine();

        $this->assertTrue(ModpacksPage::canInstall());
        $this->assertFalse(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->assertOk()->assertActionHidden('minecraft_version');
        Livewire::test(ModpacksPage::class)->call('changeMinecraftVersion', 'fabric', '26.2', '0.20.1')->assertForbidden();
        $this->assertNoMetadataRequest();
    }

    public function test_an_owner_with_the_switch_setting_changes_the_version_through_the_form(): void
    {
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $server = $this->ownerOnFabricServer();
        $this->fakeMeta();

        Livewire::test(ModpacksPage::class)
            ->assertActionVisible('minecraft_version')
            ->callAction('minecraft_version', ['loader' => 'fabric', 'minecraft' => '26.2', 'loader_version' => '0.19.5'])
            ->assertHasNoActionErrors()
            ->assertNotified(trans('modpacks-plus::strings.version.started', ['target' => 'Fabric 26.2']));

        $install = PackInstall::query()->firstOrFail();
        $this->assertSame(PackInstall::MODE_VERSION, $install->mode);
        $this->assertSame($server->id, $install->server_id);
        $this->assertSame('fabric', $install->loader_switch['loader'] ?? null);
        $this->assertSame('26.2', $install->loader_switch['minecraft'] ?? null);
        $this->assertSame('0.19.5', $install->loader_switch['loader_version'] ?? null);
        $this->assertSame($this->fabric->id, $install->loader_switch['egg_id'] ?? null);
        $this->assertSame(['loader' => 'fabric', 'minecraft' => '1.20.1'], $install->loader_switch['from'] ?? null);
        $this->assertTrue($install->loader_switch['keep_mods'] ?? false);
        Queue::assertPushed(VersionPrepareStep::class);
    }

    public function test_an_empty_loader_version_is_the_newest_stable_build(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta();

        Livewire::test(ModpacksPage::class)->call('changeMinecraftVersion', 'fabric', '26.3', null)->assertOk();

        $this->assertSame('0.20.1', PackInstall::query()->firstOrFail()->loader_switch['loader_version'] ?? null);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function tamperedChoices(): array
    {
        return [
            'minecraft not on the list' => ['fabric', '26.1', '0.20.1'],
            'snapshot' => ['fabric', '26.4-snapshot-7', '0.20.1'],
            'unstable loader build' => ['fabric', '26.2', '0.20.0'],
            'loader version not on the list' => ['fabric', '26.2', '0.21.0'],
            'injected loader version' => ['fabric', '26.2', '0.20.1 && id'],
            'quilt has no loader version' => ['quilt', '26.2', '0.1.0'],
        ];
    }

    #[DataProvider('tamperedChoices')]
    public function test_a_choice_not_on_the_fetched_lists_is_refused_and_nothing_starts(string $loader, string $minecraft, ?string $loaderVersion): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta();
        $before = $server->fresh()?->egg_id;

        Livewire::test(ModpacksPage::class)
            ->call('changeMinecraftVersion', $loader, $minecraft, $loaderVersion)
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertSame(0, PackInstall::query()->count());
        $this->assertSame($before, $server->fresh()?->egg_id);
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_loader_is_404(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta();
        $this->forbidEngine();

        Livewire::test(ModpacksPage::class)->call('changeMinecraftVersion', 'paper', '26.2', null)->assertNotFound();
        $this->assertNoMetadataRequest();
    }

    public function test_only_loaders_with_a_usable_egg_are_offered(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta();

        // Only the Fabric egg is imported: NeoForge is not an option, and a direct call names the egg to import.
        Livewire::test(ModpacksPage::class)
            ->callAction('minecraft_version', ['loader' => 'neoforge', 'minecraft' => '1.21.1', 'loader_version' => '21.1.77'])
            ->assertHasActionErrors(['loader']);
        Livewire::test(ModpacksPage::class)
            ->call('changeMinecraftVersion', 'neoforge', '1.21.1', '21.1.77')
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_a_metadata_outage_shows_a_message_and_keeps_the_page_working(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->actingAsRootAdminOn($server);
        $this->fakeMeta([LoaderVersions::FABRIC_GAME_URL => Http::response('down', 503)]);

        Livewire::test(ModpacksPage::class)
            ->mountAction('minecraft_version')
            ->assertOk()
            ->assertFormFieldExists('minecraft', fn (Select $field) => str_contains($field->toHtml(), e(InstallProgress::reason('versions_unavailable', 'meta.fabricmc.net'))) && $field->getOptions() === []);

        Livewire::test(ModpacksPage::class)
            ->assertOk()
            ->call('changeMinecraftVersion', 'fabric', '26.2', '0.20.1')
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));
        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_after_a_version_change_the_pack_is_still_shown_with_the_change(): void
    {
        $server = $this->ownerOnFabricServer();
        $this->fakeMeta();
        $base = ['server_id' => $server->id, 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 1, 'steps' => [], 'log' => [], 'progress' => []];
        PackInstall::query()->create($base + ['provider' => 'modrinth', 'pack_id' => 'AAAAAAAA', 'version_id' => 'BBBBBBBB', 'name' => 'Old Pack', 'version_number' => '1.0', 'mode' => PackInstall::MODE_INSTALL]);
        $banner = trans('modpacks-plus::strings.page.installed_pack', ['name' => 'Old Pack', 'version' => '1.0']);
        $since = trans('modpacks-plus::strings.page.version_changed_since', ['target' => 'NeoForge 1.21.1']);

        Livewire::test(ModpacksPage::class)->assertSee($banner)->assertDontSee($since);

        PackInstall::query()->create($base + ['provider' => PackInstall::VERSION_PROVIDER, 'pack_id' => 'neoforge', 'version_id' => '1.21.1', 'name' => 'NeoForge 1.21.1', 'mode' => PackInstall::MODE_VERSION, 'loader_switch' => ['loader' => 'neoforge', 'minecraft' => '1.21.1']]);

        // Still the installed pack, with the change; the version change shows in the progress section.
        Livewire::test(ModpacksPage::class)
            ->assertSee($banner)
            ->assertSee($since)
            ->assertSee(trans('modpacks-plus::strings.page.progress', ['name' => 'NeoForge 1.21.1', 'status' => trans('modpacks-plus::strings.status.installed')]));
    }
}
