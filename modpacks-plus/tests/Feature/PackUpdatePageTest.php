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
}
