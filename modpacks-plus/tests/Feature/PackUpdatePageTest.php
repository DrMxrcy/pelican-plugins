<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Egg;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\Support\PackVersions;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 36 on the Modpacks page: "Start a fresh world" on install and switch (the loader gate
 * unchanged), and the Livewire handlers re-checking every permission on direct calls.
 */
class PackUpdatePageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use PackVersions;
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

    /** @param  array<int, SubuserPermission>  $permissions  empty = the owner */
    private function actingOn(array $permissions = []): Server
    {
        [$user, $server] = $this->scenarioServer($permissions);
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    public function test_install_records_the_fresh_world_choice_and_keeps_the_world_by_default(): void
    {
        $this->actingOn();
        $this->packVersion(Mrpack::VERSION_ID, '1.0.0', ['mods/a.jar' => 'a']);

        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID)->assertOk();
        $this->assertFalse(PackInstall::query()->latest('id')->firstOrFail()->option('fresh_world'));

        PackInstall::query()->delete();
        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID, 'modrinth', true)->assertOk();
        $this->assertTrue(PackInstall::query()->latest('id')->firstOrFail()->option('fresh_world'));
    }

    public function test_a_fresh_world_needs_the_install_permissions(): void
    {
        $this->actingOn([SubuserPermission::FileRead, SubuserPermission::SettingsReinstall, SubuserPermission::FileCreate, SubuserPermission::BackupCreate]);
        $this->packVersion(Mrpack::VERSION_ID, '1.0.0', ['mods/a.jar' => 'a']);

        Livewire::test(ModpacksPage::class)->call('installPack', Mrpack::PACK_ID, Mrpack::VERSION_ID, 'modrinth', true)->assertForbidden();
        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_switching_loader_with_a_fresh_world_keeps_the_admin_gate(): void
    {
        $this->actingOn([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        Egg::factory()->create(['uuid' => LoaderEggResolverTest::NEOFORGE_UUID, 'name' => 'NeoForge', 'tags' => ['minecraft'], 'update_url' => LoaderEggResolverTest::NEOFORGE_URL]);
        $this->packVersion(Mrpack::VERSION_ID, '1.0.0', ['mods/a.jar' => 'a'], dependencies: ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);

        $this->assertFalse(ModpacksPage::canSwitchLoader());
        Livewire::test(ModpacksPage::class)->call('switchLoaderAndInstall', Mrpack::PACK_ID, Mrpack::VERSION_ID, 'modrinth', true)->assertForbidden();
        $this->assertSame(0, PackInstall::query()->count());
    }

    /** An installed version 1 with a recorded manifest; version 2 on the source, found by the update check. */
    private function installedWithUpdate(Server $server, bool $manifest = true): PackInstall
    {
        $this->packVersion(Mrpack::VERSION_ID, '1.0.0', ['mods/a.jar' => 'a']);
        $this->packVersion('VERSID02', '2.0.0', ['mods/a.jar' => 'a2']);
        $this->packVersion('VERSID03', '3.0.0', ['mods/a.jar' => 'a3'], dependencies: ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);

        return PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => 'modrinth', 'pack_id' => Mrpack::PACK_ID, 'version_id' => Mrpack::VERSION_ID,
            'name' => 'Test Pack', 'version_number' => '1.0.0', 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 2,
            'manifest' => $manifest ? [['path' => 'mods/a.jar', 'size' => 1, 'hash' => 'sha1:'.sha1('a'), 'kept' => false]] : null,
            'update_available' => true, 'latest_version_id' => 'VERSID02', 'latest_version_number' => '2.0.0', 'finished_at' => now(),
        ]);
    }

    public function test_the_banner_shows_the_update_and_the_owner_can_update(): void
    {
        $server = $this->actingOn();
        $installed = $this->installedWithUpdate($server);

        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.page.update_available', ['version' => '2.0.0']))
            ->assertSee(trans('modpacks-plus::strings.actions.update', ['version' => '2.0.0']))
            ->call('updatePack', 'VERSID02', true)
            ->assertNotified(trans('modpacks-plus::strings.install.update_started', ['name' => 'Test Pack', 'version' => '2.0.0']));

        $update = PackInstall::query()->latest('id')->firstOrFail();
        $this->assertSame(PackInstall::MODE_UPDATE, $update->mode);
        $this->assertSame($installed->id, $update->previous_install_id);
        $this->assertSame('VERSID02', $update->version_id);
        $this->assertTrue($update->option('overwrite_changes'));
        $this->assertFalse($update->option('fresh_world'));
        $this->assertSame(['prepare', 'backup', 'update_diff', 'update_apply', 'download', 'overrides', 'properties', 'finalize'], array_column($update->steps ?? [], 'key'));
    }

    public function test_a_viewer_sees_no_update_button_and_a_direct_call_is_403(): void
    {
        $server = $this->actingOn([SubuserPermission::FileRead]);
        $this->installedWithUpdate($server);

        Livewire::test(ModpacksPage::class)
            ->assertSee(trans('modpacks-plus::strings.page.update_available', ['version' => '2.0.0']))
            ->assertDontSee(trans('modpacks-plus::strings.actions.update', ['version' => '2.0.0']))
            ->call('updatePack', 'VERSID02')
            ->assertForbidden();
        $this->assertSame(0, PackInstall::query()->where('mode', PackInstall::MODE_UPDATE)->count());
    }

    /** @return array<string, array{SubuserPermission}> */
    public static function installPermissions(): array
    {
        return array_combine(array_map(fn (SubuserPermission $permission) => $permission->value, self::INSTALL_PERMISSIONS), array_map(fn (SubuserPermission $permission) => [$permission], self::INSTALL_PERMISSIONS));
    }

    #[DataProvider('installPermissions')]
    public function test_updating_needs_every_install_permission(SubuserPermission $missing): void
    {
        $server = $this->actingOn(array_values(array_filter([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS], fn (SubuserPermission $permission) => $permission !== $missing)));
        $this->installedWithUpdate($server);

        Livewire::test(ModpacksPage::class)->call('updatePack', 'VERSID02')->assertForbidden();
        $this->assertSame(0, PackInstall::query()->where('mode', PackInstall::MODE_UPDATE)->count());
    }

    public function test_a_version_for_another_loader_is_never_an_update(): void
    {
        $server = $this->actingOn();
        $this->installedWithUpdate($server);

        Livewire::test(ModpacksPage::class)
            ->call('updatePack', 'VERSID03')
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));
        $this->assertSame(0, PackInstall::query()->where('mode', PackInstall::MODE_UPDATE)->count());
    }

    public function test_an_install_without_a_manifest_can_not_be_updated(): void
    {
        $server = $this->actingOn();
        $this->installedWithUpdate($server, manifest: false);

        Livewire::test(ModpacksPage::class)
            ->assertDontSee(trans('modpacks-plus::strings.actions.update', ['version' => '2.0.0']))
            ->call('updatePack', 'VERSID02')
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));
        $this->assertSame(0, PackInstall::query()->where('mode', PackInstall::MODE_UPDATE)->count());
    }

    public function test_an_unknown_or_hostile_version_id_is_a_404(): void
    {
        $server = $this->actingOn();
        $this->installedWithUpdate($server);

        Livewire::test(ModpacksPage::class)->call('updatePack', '../x')->assertNotFound();
        $this->assertSame(0, PackInstall::query()->where('mode', PackInstall::MODE_UPDATE)->count());
    }
}
