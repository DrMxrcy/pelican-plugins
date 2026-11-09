<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Tests\Support\AtLauncher;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 32 step 4: FTB and ATLauncher source tabs. Each lists its packs, says when CurseForge
 * files will need a key, and installs through the same server-side checks as the other tabs.
 */
class FtbAtLauncherPageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        Filament::setCurrentPanel('server');
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', false);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    private function actingOnForge(): Server
    {
        [$user, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    public function test_both_sources_are_tabs(): void
    {
        $this->assertSame(['modrinth', 'curseforge', 'ftb', 'atlauncher'], PackProviders::IDS);
        $this->assertSame('FTB', trans('modpacks-plus::strings.page.sources.ftb'));
        $this->assertSame('ATLauncher', trans('modpacks-plus::strings.page.sources.atlauncher'));
    }

    public function test_the_ftb_tab_lists_packs_says_what_needs_curseforge_and_installs(): void
    {
        $this->actingOnForge();
        Http::fake([
            Ftb::API.'modpack/popular/installs/100' => Http::response(['packs' => [Ftb::PACK_ID]]),
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version([])),
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
            '*' => Http::response(['hits' => [], 'total_hits' => 0]),
        ]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', FtbPackProvider::ID)
            ->assertSet('source', FtbPackProvider::ID)
            ->assertSee(trans('modpacks-plus::strings.page.ftb_curseforge'))
            ->call('loadTable')
            ->assertSee('FTB Test Pack')
            ->call('installPack', (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, FtbPackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'FTB Test Pack']));

        $install = PackInstall::query()->sole();
        $this->assertSame([FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID], [$install->provider, $install->pack_id, $install->version_id]);
    }

    public function test_the_curseforge_notice_goes_once_curseforge_is_set_up(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        Http::fake(['*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', FtbPackProvider::ID)
            ->assertDontSee(trans('modpacks-plus::strings.page.ftb_curseforge'))
            ->call('setSource', AtLauncherPackProvider::ID)
            ->assertDontSee(trans('modpacks-plus::strings.page.atlauncher_curseforge'));
    }

    public function test_the_atlauncher_tab_lists_packs_and_installs(): void
    {
        $this->actingOnForge();
        Http::fake([
            AtLauncher::API.'packs/full/public' => Http::response(AtLauncher::data([AtLauncher::pack()])),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/'.AtLauncher::VERSION.'/Configs.json' => Http::response(AtLauncher::configs([])),
            '*' => Http::response(['hits' => [], 'total_hits' => 0]),
        ]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', AtLauncherPackProvider::ID)
            ->assertSee(trans('modpacks-plus::strings.page.atlauncher_curseforge'))
            ->call('loadTable')
            ->assertSee('Test Pack')
            ->call('installPack', AtLauncher::PACK, AtLauncher::VERSION, AtLauncherPackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'Test Pack']));

        $this->assertSame(AtLauncherPackProvider::ID, PackInstall::query()->sole()->provider);
    }

    public function test_hostile_ids_on_the_new_tabs_are_a_404_and_never_requested(): void
    {
        $this->actingOnForge();
        Http::fake(['*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        Livewire::test(ModpacksPage::class)->call('installPack', '../1', '2', FtbPackProvider::ID)->assertNotFound();
        Livewire::test(ModpacksPage::class)->call('installPack', AtLauncher::PACK, '../../x', AtLauncherPackProvider::ID)->assertNotFound();
        Livewire::test(ModpacksPage::class)->call('installPack', 'a/b', AtLauncher::VERSION, AtLauncherPackProvider::ID)->assertNotFound();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'modpacks.ch') || str_contains($request->url(), 'atlauncher') || str_contains($request->url(), 'nodecdn'));
        $this->assertSame(0, PackInstall::query()->count());
    }
}
