<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 41: a CurseForge modpack opened from a pasted link or Project ID, for API keys CurseForge
 * refuses search for (live: /v1/mods/search 403 while /v1/mods/{id} answers). The pasted text is
 * never fetched: only api.curseforge.com paths built from a parsed id or slug are requested.
 */
class CurseForgeLinkPageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const INSTALL_PERMISSIONS = [SubuserPermission::SettingsReinstall, SubuserPermission::FileCreate, SubuserPermission::FileDelete, SubuserPermission::BackupCreate];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        Filament::setCurrentPanel('server');
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        config()->set('modpacks-plus.curseforge.mirror', false);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  array<int, SubuserPermission>  $permissions  empty = the owner */
    private function actingOnForge(array $permissions = []): Server
    {
        [$user, $server] = $this->scenarioServer($permissions, ['minecraft', 'forge']);
        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    /**
     * CurseForge answering for the test pack; search answers $searchStatus (403 = refused for this key).
     *
     * @param  array<string, mixed>  $extra
     */
    private function fakeCurseForge(int $searchStatus = 200, array $extra = []): void
    {
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, 'zip');
        $base = CurseForge::API;

        Http::fake(array_merge([
            $base.'mods/search*' => $searchStatus === 200
                ? Http::response(['data' => [CurseForge::mod()], 'pagination' => ['totalCount' => 1]])
                : Http::response(['error' => 'Forbidden'], $searchStatus),
            $base.'categories*' => Http::response(CurseForge::data([['id' => 4472, 'name' => 'Tech', 'classId' => 4471]])),
            $base.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            $base.'mods/'.CurseForge::PACK_ID.'/files*' => Http::response(CurseForge::data([$pack])),
            $base.'mods/'.CurseForge::PACK_ID.'/description' => Http::response(CurseForge::data('<p>About</p>')),
            $base.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
        ], $extra, ['*' => Http::response(['hits' => [], 'total_hits' => 0])]));
    }

    private function curseForgeTab(): Testable
    {
        return Livewire::test(ModpacksPage::class)->call('setSource', CurseForgePackProvider::ID);
    }

    /** Every request that left the panel for CurseForge went to api.curseforge.com/v1 (Modrinth is the Modrinth tab and "Also on Modrinth"). */
    private function assertOnlyCurseForgeApi(): void
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!str_starts_with($request->url(), 'https://api.modrinth.com/')) {
                $this->assertStringStartsWith('https://api.curseforge.com/v1/', $request->url());
            }
        }
    }

    public function test_a_project_id_opens_the_pack_details_and_install_works_as_from_search(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(searchStatus: 403);

        $this->curseForgeTab()
            ->assertActionVisible('open_curseforge_link')
            ->callAction('open_curseforge_link', ['link' => (string) CurseForge::PACK_ID])
            ->assertHasNoActionErrors()
            ->assertActionMounted('curseforgePack')
            ->assertMountedActionModalSee(['Test CF Pack', trans('modpacks-plus::strings.details.versions'), trans('modpacks-plus::strings.actions.install')])
            ->assertMountedActionModalDontSee(CurseForge::KEY)
            ->call('installPack', (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)
            ->assertNotified(trans('modpacks-plus::strings.install.started', ['name' => 'Test CF Pack']));

        $install = PackInstall::query()->sole();
        $this->assertSame(CurseForgePackProvider::ID, $install->provider);
        $this->assertSame((string) CurseForge::PACK_ID, $install->pack_id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.curseforge.com/v1/mods/'.CurseForge::PACK_ID);
        $this->assertOnlyCurseForgeApi();
    }

    public function test_a_project_link_opens_the_pack(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(searchStatus: 403);

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => 'https://www.curseforge.com/projects/'.CurseForge::PACK_ID])
            ->assertActionMounted('curseforgePack');
    }

    public function test_a_slug_link_is_resolved_by_slug_search(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge();

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => 'https://www.curseforge.com/minecraft/modpacks/test-cf-pack/files/'.CurseForge::FILE_ID])
            ->assertActionMounted('curseforgePack')
            ->assertMountedActionModalSee('Test CF Pack');

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://api.curseforge.com/v1/mods/search?')
                && ($query['slug'] ?? null) === 'test-cf-pack' && ($query['classId'] ?? null) === '4471' && ($query['gameId'] ?? null) === '432';
        });
        // The pasted URL itself is never requested.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'www.curseforge.com'));
        $this->assertOnlyCurseForgeApi();
    }

    public function test_a_slug_link_with_search_refused_asks_for_the_project_id(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(searchStatus: 403);

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => 'https://www.curseforge.com/minecraft/modpacks/test-cf-pack'])
            ->assertNotified(trans('modpacks-plus::strings.reasons.curseforge_link_needs_id', ['detail' => 'test-cf-pack']))
            ->assertActionNotMounted('curseforgePack');

        $this->assertOnlyCurseForgeApi();
    }

    public function test_a_slug_search_answering_another_pack_is_not_found(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(extra: [
            CurseForge::API.'mods/search*' => Http::response(['data' => [CurseForge::mod(999, ['slug' => 'other-pack'])], 'pagination' => ['totalCount' => 1]]),
        ]);

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => 'https://www.curseforge.com/minecraft/modpacks/test-cf-pack'])
            ->assertNotified(trans('modpacks-plus::strings.reasons.curseforge_not_found', ['detail' => 'test-cf-pack']))
            ->assertActionNotMounted('curseforgePack');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'mods/999'));
    }

    public function test_a_mod_is_not_a_modpack(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(extra: [
            CurseForge::API.'mods/238222' => Http::response(CurseForge::data(CurseForge::modEntry(238222, 'Just Enough Items'))),
        ]);

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => '238222'])
            ->assertNotified(trans('modpacks-plus::strings.reasons.not_a_modpack', ['detail' => '238222']))
            ->assertActionNotMounted('curseforgePack');

        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_hostile_links_are_refused_without_any_request(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge();

        foreach ([
            'https://evil.example/minecraft/modpacks/test-cf-pack',
            'https://www.curseforge.com.evil.example/minecraft/modpacks/test-cf-pack',
            'https://www.curseforge.com/minecraft/mc-mods/jei',
            'https://www.curseforge.com/wow/addons/details',
            'javascript:alert(1)',
            '-1',
            '99999999999',
            'pаck',
        ] as $link) {
            // A refused link keeps the box open (halted) to fix the text.
            $this->curseForgeTab()->callAction('open_curseforge_link', ['link' => $link])
                ->assertNotified(trans('modpacks-plus::strings.reasons.curseforge_link_invalid'))
                ->assertActionHalted('open_curseforge_link')
                ->assertActionNotMounted('curseforgePack');
        }

        // Nothing but the tab's own category list was asked.
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!str_starts_with($request->url(), 'https://api.modrinth.com/')) {
                $this->assertStringStartsWith('https://api.curseforge.com/v1/categories?', $request->url());
            }
        }
    }

    public function test_a_tampered_details_mount_requests_nothing(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge();
        $page = $this->curseForgeTab();

        $page->mountAction('curseforgePack', ['packId' => '../../evil'])
            ->assertMountedActionModalSee(trans('modpacks-plus::strings.reasons.curseforge_link_invalid'));
        $this->curseForgeTab()->mountAction('curseforgePack', ['packId' => ['x']])
            ->assertMountedActionModalSee(trans('modpacks-plus::strings.reasons.curseforge_link_invalid'));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.curseforge.com/v1/mods') || str_contains($request->url(), 'evil'));
    }

    public function test_without_install_permissions_a_linked_pack_shows_no_install_and_installing_is_forbidden(): void
    {
        $this->actingOnForge([SubuserPermission::FileRead]);
        $this->fakeCurseForge(searchStatus: 403);

        $this->curseForgeTab()
            ->callAction('open_curseforge_link', ['link' => (string) CurseForge::PACK_ID])
            ->assertActionMounted('curseforgePack')
            ->assertMountedActionModalSee('Test CF Pack')
            ->assertMountedActionModalDontSee(trans('modpacks-plus::strings.actions.install'));

        Livewire::test(ModpacksPage::class)
            ->call('installPack', (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, CurseForgePackProvider::ID)
            ->assertForbidden();
        $this->assertSame(0, PackInstall::query()->count());
    }

    public function test_without_curseforge_set_up_the_link_box_is_hidden_and_asks_nothing(): void
    {
        $this->actingOnForge([SubuserPermission::FileRead, ...self::INSTALL_PERMISSIONS]);
        config()->set('modpacks-plus.curseforge.api_key', null);
        Http::fake();

        $page = $this->curseForgeTab()->assertActionHidden('open_curseforge_link');
        $page->mountAction('curseforgePack', ['packId' => (string) CurseForge::PACK_ID])
            ->assertActionNotMounted('curseforgePack');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curse'));
    }

    public function test_the_link_box_is_only_on_the_curseforge_tab(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge();

        Livewire::test(ModpacksPage::class)->assertActionHidden('open_curseforge_link');
    }

    public function test_search_refused_for_the_key_points_at_the_link_box(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(searchStatus: 403);

        $this->curseForgeTab()
            ->call('loadTable')
            ->assertSee(e(trans('modpacks-plus::strings.reasons.curseforge_search_refused')), false)
            ->assertDontSee(e(trans('modpacks-plus::strings.reasons.curseforge_key_rejected', ['detail' => 'mods/search (HTTP 403)'])), false);
    }

    public function test_a_401_on_search_still_says_the_key_was_rejected(): void
    {
        $this->actingOnForge();
        $this->fakeCurseForge(searchStatus: 401);

        $this->curseForgeTab()
            ->call('loadTable')
            ->assertSee(e(trans('modpacks-plus::strings.reasons.curseforge_key_rejected', ['detail' => 'mods/search (HTTP 401)'])), false);
    }
}
