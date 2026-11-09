<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 31 step 6: the CurseForge source tab. Without a key or the mirror it explains how to
 * set CurseForge up and asks nothing; a failing mirror shows "unavailable" and starts no
 * install; a pack with a same-title Modrinth modpack offers "Also on Modrinth".
 */
class CurseForgePageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const MIRROR = 'api.curse.tools/v1/';

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

    /**
     * CurseForge (at $base) answering for the test pack.
     *
     * @param  array<string, mixed>  $extra
     */
    private function fakeCurseForge(string $base = CurseForge::API, array $extra = []): void
    {
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, 'zip');

        Http::fake(array_merge([
            $base.'mods/search*' => Http::response(['data' => [CurseForge::mod()], 'pagination' => ['totalCount' => 1]]),
            $base.'categories*' => Http::response(CurseForge::data([['id' => 4472, 'name' => 'Tech', 'classId' => 4471]])),
            $base.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            $base.'mods/'.CurseForge::PACK_ID.'/files*' => Http::response(CurseForge::data([$pack])),
            $base.'mods/'.CurseForge::PACK_ID.'/description' => Http::response(CurseForge::data('<p>About</p>')),
            $base.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
        ], $extra, ['*' => Http::response(['hits' => [], 'total_hits' => 0])]));
    }

    public function test_without_a_key_or_mirror_the_tab_explains_and_asks_nothing(): void
    {
        $this->actingOnForge();
        Http::fake();

        $page = Livewire::test(ModpacksPage::class);
        // Whatever the Modrinth tab loaded on mount; the CurseForge tab adds nothing.
        $before = count(Http::recorded());

        $page->call('setSource', CurseForgePackProvider::ID)
            ->assertSet('source', CurseForgePackProvider::ID)
            ->call('loadTable')
            ->assertSee(trans('modpacks-plus::strings.page.curseforge_not_configured'))
            ->assertSee(trans('modpacks-plus::strings.reasons.curseforge_not_configured'))
            ->call('installPack', (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertCount($before, Http::recorded());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curse'));
        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_the_modrinth_tab_does_not_show_the_curseforge_notice(): void
    {
        $this->actingOnForge();
        Http::fake(['*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        Livewire::test(ModpacksPage::class)
            ->assertSet('source', ModrinthPackProvider::ID)
            ->assertDontSee(trans('modpacks-plus::strings.page.curseforge_not_configured'));
    }

    public function test_an_unknown_source_is_never_used(): void
    {
        $this->actingOnForge();
        Http::fake(['*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', 'evil')
            ->assertSet('source', ModrinthPackProvider::ID)
            // A tampered property falls back to Modrinth.
            ->set('source', 'https://evil.example')
            ->call('loadTable')
            ->assertOk()
            ->call('installPack', '1', '2', 'evil')
            ->assertNotFound();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_with_a_key_search_and_install_work(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fakeCurseForge();

        Livewire::test(ModpacksPage::class)
            ->call('setSource', CurseForgePackProvider::ID)
            ->call('loadTable')
            ->assertSee('Test CF Pack')
            ->assertDontSee(CurseForge::KEY)
            ->assertDontSee('SECRETkeyDoNotLeak')
            ->call('installPack', (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'Test CF Pack']));

        $install = PackInstall::query()->sole();
        $this->assertSame(CurseForgePackProvider::ID, $install->provider);
        $this->assertSame((string) CurseForge::PACK_ID, $install->pack_id);
        $this->assertSame((string) CurseForge::FILE_ID, $install->version_id);
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', (string) json_encode($install->toArray()));
    }

    public function test_hostile_curseforge_ids_are_a_404(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        Http::fake();

        Livewire::test(ModpacksPage::class)->call('installPack', '../1', (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)->assertNotFound();
        Livewire::test(ModpacksPage::class)->call('installPack', (string) CurseForge::PACK_ID, 'abc', CurseForgePackProvider::ID)->assertNotFound();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curse'));
    }

    public function test_a_failing_mirror_shows_unavailable_and_starts_no_install(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.mirror', true);
        Http::fake(['*' => Http::response('slow down', 429)]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', CurseForgePackProvider::ID)
            ->assertSee(trans('modpacks-plus::strings.page.curseforge_mirror'))
            ->call('loadTable')
            ->assertSee('CurseForge unavailable — add an API key')
            ->call('installPack', (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.refused'));

        $this->assertSame(0, PackInstall::query()->count());
        // CurseForge was only asked through the mirror (the Modrinth tab's categories load on mount).
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!str_contains($request->url(), 'api.modrinth.com')) {
                $this->assertStringStartsWith('https://api.curse.tools/v1/', $request->url());
            }
        }
    }

    public function test_the_mirror_lists_packs(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.mirror', true);
        $this->fakeCurseForge(self::MIRROR);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', CurseForgePackProvider::ID)
            ->call('loadTable')
            ->assertSee('Test CF Pack');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.curseforge.com'));
    }

    /**
     * Visible header actions of the CurseForge detail view, by name.
     *
     * @return array<string, string>
     */
    private function detailActions(): array
    {
        $test = Livewire::test(ModpacksPage::class)->call('setSource', CurseForgePackProvider::ID);
        /** @var ModpacksPage $instance */
        $instance = $test->instance();
        $components = (new \ReflectionMethod($instance, 'detailSchema'))->invoke($instance, (string) CurseForge::PACK_ID);
        $schema = Schema::make($instance)->components($components);

        $labels = [];
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Section) {
                foreach ($component->getHeaderActions() as $action) {
                    if ($action->isVisible()) {
                        $labels[$action->getName()] = (string) $action->getLabel();
                    }
                }
            }
        }

        return $labels;
    }

    public function test_a_same_title_modrinth_pack_shows_also_on_modrinth(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fakeCurseForge(extra: [
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [
                ['project_id' => 'OTHER001', 'title' => 'Test CF Pack Plus'],
                ['project_id' => 'MRPACK01', 'title' => '  test cf PACK '],
            ], 'total_hits' => 2]),
        ]);

        $actions = $this->detailActions();

        $this->assertSame(trans('modpacks-plus::strings.details.also_on_modrinth'), $actions['also_on_modrinth'] ?? null);
        // Searched by title, filtered to the pack's loader.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.modrinth.com/v2/search')
            && str_contains(urldecode($request->url()), 'categories:forge') && str_contains(urldecode($request->url()), 'Test CF Pack'));
    }

    public function test_no_same_title_modrinth_pack_shows_nothing(): void
    {
        $this->actingOnForge();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fakeCurseForge(extra: [
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [['project_id' => 'OTHER001', 'title' => 'Test CF Pack Plus']], 'total_hits' => 1]),
        ]);

        $this->assertArrayNotHasKey('also_on_modrinth', $this->detailActions());
    }

    public function test_also_on_modrinth_switches_to_the_modrinth_listing(): void
    {
        $this->actingOnForge();
        Http::fake(['*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        Livewire::test(ModpacksPage::class)
            ->call('setSource', CurseForgePackProvider::ID)
            ->call('showOnModrinth', 'Test CF Pack')
            ->assertSet('source', ModrinthPackProvider::ID)
            ->assertSet('tableSearch', 'Test CF Pack');
    }
}
