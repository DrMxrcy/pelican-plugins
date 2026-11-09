<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthDatapackPage;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Plan 16: a warning banner at the top of the page when installed projects have no build for
 * the server's current Minecraft version + loader. Runs the real compatibility summary
 * (cache + getProjectVersionsBulk) against a faked Modrinth; only the server side (daemon,
 * egg variables) is stubbed.
 */
class CompatibilityBannerTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private string $minecraftVersion = '26.3';

    private bool $loaderKnown = true;

    /** @var array<int, array<string, string>>|null */
    private ?array $installed = [];

    /** @var list<string> service methods called that write files or metadata */
    private array $writes = [];

    private bool $writesExpected = false;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
        Filament::setCurrentPanel('server');

        /** @var MinecraftModrinthService&MockInterface $service */
        $service = MinecraftModrinth::partialMock();
        $service->shouldReceive('getMinecraftVersion')->andReturnUsing(fn () => $this->minecraftVersion);
        $service->shouldReceive('getLoaderFromServer')->andReturnUsing(fn () => $this->loaderKnown ? [
            'icon' => '',
            'name' => 'fabric',
            'supported_project_types' => ['mod'],
            'display_name' => 'Fabric',
        ] : null);
        $service->shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        $service->shouldReceive('listFolder')->andReturn([]);
        $service->shouldReceive('getCategories')->andReturn([]);
        $service->shouldReceive('getProjects')->andReturn(['hits' => [], 'total_hits' => 0, 'versions' => []]);
        $service->shouldReceive('getProjectFolder')->andReturn('world/datapacks');

        // Read-only feature: file and metadata writes are recorded and must not happen (see
        // assertPostConditions()), except in the test that uninstalls through the page.
        foreach (['downloadFile', 'deleteFile', 'saveModMetadata', 'removeModMetadata'] as $method) {
            $service->shouldReceive($method)->andReturnUsing(function (...$args) use ($method) {
                $this->writes[] = $method;

                return $method === 'removeModMetadata' ? $this->uninstall($args[2]) : true;
            });
        }
    }

    protected function assertPostConditions(): void
    {
        if (!$this->writesExpected) {
            $this->assertSame([], $this->writes);
        }
    }

    /** Fake removeModMetadata(): drops the project from the installed list. */
    private function uninstall(string $projectId): bool
    {
        $this->installed = array_values(array_filter($this->installed ?? [], fn (array $mod) => $mod['project_id'] !== $projectId));

        return true;
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /**
     * @param  array<int, SubuserPermission>  $permissions  empty = the server owner
     * @param  array<int, string>  $features
     */
    private function account(array $permissions = [], array $features = ['modrinth_mods']): void
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $server->egg->forceFill(['features' => $features])->save();

        $this->actingAs($user);
        Filament::setTenant($server);
    }

    /** @param  array<string, string>  $versions  project id => installed version id */
    private function install(array $versions): void
    {
        $this->installed = [];

        foreach ($versions as $projectId => $versionId) {
            $this->installed[] = [
                'project_id' => $projectId,
                'project_slug' => $projectId,
                'project_title' => "Title of $projectId",
                'version_id' => $versionId,
                'version_number' => "$versionId.0",
                'filename' => "$projectId.jar",
                'installed_at' => '2026-01-01T00:00:00+00:00',
            ];
        }
    }

    /**
     * "ok" and "old" have builds (ok1, old2) for 26.3; "gone" has none. On 26.4 every
     * installed version has a build.
     */
    private function fakeModrinth(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains(urldecode($request->url()), '"26.4"')) {
                return Http::response([
                    ['id' => 'ok1', 'version_number' => '1', 'date_published' => '2026-02-01'],
                    ['id' => 'old1', 'version_number' => '1', 'date_published' => '2026-02-01'],
                    ['id' => 'g1', 'version_number' => '1', 'date_published' => '2026-02-01'],
                ]);
            }

            return match (true) {
                str_contains($request->url(), '/project/ok/') => Http::response([['id' => 'ok1', 'version_number' => '1', 'date_published' => '2026-01-01']]),
                str_contains($request->url(), '/project/old/') => Http::response([['id' => 'old2', 'version_number' => '2.0', 'date_published' => '2026-01-01']]),
                default => Http::response([]),
            };
        });
    }

    private function forgetVersionsCache(): void
    {
        foreach (['ok', 'old', 'gone'] as $projectId) {
            cache()->forget("modrinth_versions:$projectId:{$this->minecraftVersion}:fabric");
        }
    }

    private function page(string $page = MinecraftModrinthModPage::class): Testable
    {
        return Livewire::test($page)->call('loadCompatibilityBanner');
    }

    private function heading(int $count, string $version = '26.3'): string
    {
        return trans_choice('modrinth-plus::strings.compatibility.banner.heading', $count, ['count' => $count, 'version' => $version]);
    }

    private function description(): string
    {
        return trans('modrinth-plus::strings.compatibility.banner.description', ['loader' => 'Fabric']);
    }

    public function test_banner_shows_the_count_and_opens_the_check_listing_the_same_projects(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1']);

        $this->page()
            ->assertSet('incompatibleCount', 2)
            ->assertSee($this->heading(2))
            ->assertSee($this->description())
            ->assertActionVisible(TestAction::make('open_compatibility_check')->schemaComponent('compatibility_banner'))
            ->callAction(TestAction::make('open_compatibility_check')->schemaComponent('compatibility_banner'))
            ->assertActionMounted('check_compatibility')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.summary', ['count' => 2, 'total' => 3]))
            ->assertMountedActionModalSee('Title of old')
            ->assertMountedActionModalSee('Title of gone')
            ->assertMountedActionModalDontSee('Title of ok');
    }

    public function test_singular_heading(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'gone' => 'g1']);

        $this->page()->assertSee($this->heading(1));
        $this->assertStringContainsString('1 installed project doesn', $this->heading(1));
    }

    public function test_first_render_never_waits_on_modrinth_and_queues_the_banner(): void
    {
        $this->account();
        Http::fake();
        $this->install(['gone' => 'g1']);

        Livewire::test(MinecraftModrinthModPage::class)
            ->assertOk()
            ->assertJs('$wire.loadCompatibilityBanner()')
            ->assertSet('incompatibleCount', null)
            ->assertDontSee($this->description());

        Http::assertNothingSent();
    }

    public function test_changed_installs_drop_the_cached_summary_and_reload_the_banner(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['gone' => 'g1']);
        MinecraftModrinth::shouldReceive('scanAndImport')->once()
            ->andReturn(['imported' => [], 'not_on_modrinth' => [], 'skipped' => [], 'duplicates' => [], 'error' => false]);

        $page = $this->page()->assertSee($this->heading(1));
        Http::assertSentCount(1);

        $page->callAction('scan_folder')
            // The old count stays until the queued reload replaces it: no flicker.
            ->assertSet('incompatibleCount', 1)
            ->assertJs('$wire.loadCompatibilityBanner()');

        // The summary cache was dropped: the next load asks Modrinth again.
        $this->forgetVersionsCache();
        $page->call('loadCompatibilityBanner')->assertSee($this->heading(1));
        Http::assertSentCount(2);
    }

    public function test_no_banner_when_everything_is_compatible(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old2']);

        $this->page()
            ->assertOk()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description())
            ->assertActionDoesNotExist(TestAction::make('open_compatibility_check')->schemaComponent('compatibility_banner'));
    }

    public function test_no_banner_when_modrinth_is_unreachable(): void
    {
        $this->account();
        Http::fake(['api.modrinth.com/*' => Http::failedConnection()]);
        $this->install(['gone' => 'g1', 'old' => 'old1']);

        $this->page()
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description());
    }

    public function test_no_banner_when_modrinth_errors(): void
    {
        $this->account();
        Http::fake(['api.modrinth.com/*' => Http::response('oops', 500)]);
        $this->install(['gone' => 'g1']);

        $this->page()
            ->assertOk()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description());
    }

    public function test_no_banner_when_the_metadata_file_is_unreadable(): void
    {
        $this->account();
        Http::fake();
        $this->installed = null;

        $this->page()
            ->assertOk()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description());

        Http::assertNothingSent();
    }

    public function test_no_banner_when_the_loader_is_unknown(): void
    {
        $this->account();
        Http::fake();
        $this->loaderKnown = false;
        $this->install(['gone' => 'g1']);

        $this->page()->assertSet('incompatibleCount', 0)->assertDontSee(trans('modrinth-plus::strings.compatibility.banner.description', ['loader' => trans('modrinth-plus::strings.page.unknown')]));

        Http::assertNothingSent();
    }

    public function test_a_failing_summary_never_breaks_the_page(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('getCompatibilitySummary')->andThrow(new \RuntimeException('boom'));

        $this->page()
            ->assertOk()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description());
    }

    public function test_second_page_load_within_the_ttl_makes_no_modrinth_request(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1']);

        $this->page()->assertSee($this->heading(2));
        Http::assertSentCount(3);

        // Only the summary cache may save the second load.
        $this->forgetVersionsCache();
        $this->travel(5)->hours();

        $this->page()->assertSee($this->heading(2));
        Http::assertSentCount(3);
    }

    public function test_changing_the_minecraft_version_refreshes_the_banner(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'gone' => 'g1']);

        $this->page()->assertSee($this->heading(1));

        $this->minecraftVersion = '26.4';

        $this->page()
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->heading(1, '26.4'))
            ->assertDontSee($this->description());
    }

    public function test_installing_updating_and_uninstalling_refresh_the_banner(): void
    {
        $this->account();
        $this->fakeModrinth();

        $this->install(['old' => 'old1']);
        $this->page()->assertSee($this->heading(1));

        // update old1 -> old2
        $this->install(['old' => 'old2']);
        $this->page()->assertSet('incompatibleCount', 0);

        // install an incompatible one
        $this->install(['old' => 'old2', 'gone' => 'g1']);
        $this->page()->assertSee($this->heading(1));

        // uninstall it again
        $this->install(['old' => 'old2']);
        $this->page()->assertSet('incompatibleCount', 0)->assertDontSee($this->description());
    }

    public function test_uninstalling_through_the_page_reloads_the_banner_once_and_keeps_the_old_count_until_then(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'gone' => 'g1']);

        /** @var MockInterface $service */
        $service = MinecraftModrinth::getFacadeRoot();
        $service->shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(fn (array $mods) => array_map(fn (array $mod) => [
            'project_id' => $mod['project_id'],
            'slug' => $mod['project_id'],
            'title' => $mod['project_title'],
            'description' => '',
            'icon_url' => null,
            'author' => 'someone',
            'downloads' => 1,
            'date_modified' => '2026-01-01T00:00:00Z',
            'project_type' => 'mod',
            'server_side' => 'required',
            'client_side' => 'optional',
        ], array_values($mods)));
        $this->writesExpected = true;

        $page = $this->page()
            ->assertSee($this->heading(1))
            ->set('activeTab', 'installed')
            ->loadTable()
            ->callAction(TestAction::make('uninstall')->table('0'))
            ->assertSet('incompatibleCount', 1);

        $this->assertSame(['deleteFile', 'removeModMetadata'], $this->writes);
        $this->assertSame(['ok'], array_column($this->installed ?? [], 'project_id'));
        $this->assertCount(1, array_filter($page->effects['xjs'] ?? [], fn (array $js) => $js['expression'] === '$wire.loadCompatibilityBanner()'));

        // The queued reload: "gone" is uninstalled, so the banner goes away.
        $page->call('loadCompatibilityBanner')
            ->assertSet('incompatibleCount', 0)
            ->assertDontSee($this->description());
    }

    public function test_read_only_subuser_sees_the_banner(): void
    {
        $this->account([SubuserPermission::FileRead]);
        $this->fakeModrinth();
        $this->install(['gone' => 'g1']);

        $this->page()
            ->assertSee($this->heading(1))
            ->callAction(TestAction::make('open_compatibility_check')->schemaComponent('compatibility_banner'))
            ->assertActionMounted('check_compatibility');
    }

    public function test_subuser_without_file_read_gets_neither_banner_nor_button(): void
    {
        $this->account([SubuserPermission::ControlConsole]);
        Http::fake();
        $this->install(['gone' => 'g1']);

        Livewire::test(MinecraftModrinthModPage::class)->assertForbidden();

        // The load method and the banner check the permission themselves too.
        $page = new MinecraftModrinthModPage();
        $page->loadCompatibilityBanner();

        $this->assertNull($page->incompatibleCount);
        Http::assertNothingSent();
    }

    public function test_datapack_page_shows_the_banner_too(): void
    {
        $this->account([], ['modrinth_datapacks']);
        $this->fakeModrinth();
        $this->install(['gone' => 'g1']);

        $this->page(MinecraftModrinthDatapackPage::class)->assertSee($this->heading(1));
    }

    public function test_german_strings(): void
    {
        app()->setLocale('de');
        $this->account();
        $this->fakeModrinth();
        $this->install(['gone' => 'g1', 'old' => 'old1']);

        $this->assertNotSame(
            trans_choice('modrinth-plus::strings.compatibility.banner.heading', 2, ['count' => 2, 'version' => '26.3'], 'en'),
            $this->heading(2),
        );

        $this->page()->assertSee($this->heading(2))->assertSee($this->description());
    }

    /** Plan 18: pinned projects still count, and the banner says how many are pinned. */
    public function test_banner_counts_pinned_projects_and_says_how_many_are_pinned(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1']);
        $this->installed[1]['pinned'] = true;

        $this->page()
            ->assertSet('incompatibleCount', 2)
            ->assertSet('incompatiblePinnedCount', 1)
            ->assertSee($this->heading(2))
            ->assertSee(trans_choice('modrinth-plus::strings.compatibility.banner.pinned', 1, ['count' => 1]));
    }

    public function test_banner_says_nothing_about_pins_when_none_are_pinned(): void
    {
        $this->account();
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1']);

        $this->page()
            ->assertSet('incompatiblePinnedCount', 0)
            ->assertDontSee(trans_choice('modrinth-plus::strings.compatibility.banner.pinned', 1, ['count' => 1]));
    }
}
