<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

/**
 * Plan 16 step 1: the cached compatibility summary behind the page banner. Counts installed
 * projects whose installed version has no build for the current Minecraft version + loader;
 * a failed Modrinth lookup ("unknown") never counts. Cached per server + project type, keyed
 * on version, loader and the installed version ids.
 */
class CompatibilitySummaryTest extends TestCase
{
    private string $minecraftVersion = '26.3';

    private ?string $loader = 'fabric';

    /** @var array<int, array<string, string>>|null */
    private ?array $installed = [];

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    private function server(int $id = 1): Server
    {
        $server = new Server();
        $server->id = $id;

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        /** @var MinecraftModrinthService&MockInterface $service */
        $service = Mockery::mock(MinecraftModrinthService::class)->makePartial();
        $service->shouldReceive('getLoaderFromServer')->andReturnUsing(fn () => $this->loader === null ? null : [
            'icon' => '',
            'name' => $this->loader,
            'supported_project_types' => ['mod'],
            'display_name' => ucfirst($this->loader),
        ]);
        $service->shouldReceive('getMinecraftVersion')->andReturnUsing(fn () => $this->minecraftVersion);
        $service->shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);

        return $service;
    }

    /**
     * The banner count for server 1's mods, or null when there is no summary.
     *
     * @phpstan-impure
     */
    private function incompatible(MinecraftModrinthService $service): ?int
    {
        return $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod)['incompatible'] ?? null;
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
                'version_number' => $versionId,
                'filename' => "$projectId.jar",
                'installed_at' => '2026-01-01T00:00:00+00:00',
            ];
        }
    }

    private function fakeModrinth(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/ok/*' => Http::response([['id' => 'ok1', 'version_number' => '1', 'date_published' => '2026-01-01']]),
            'api.modrinth.com/v2/project/old/*' => Http::response([['id' => 'old2', 'version_number' => '2', 'date_published' => '2026-01-01']]),
            'api.modrinth.com/v2/project/gone/*' => Http::response([]),
            'api.modrinth.com/v2/project/broken/*' => Http::response('oops', 500),
        ]);
    }

    public function test_counts_incompatible_and_needs_update_but_not_unknown(): void
    {
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1', 'broken' => 'b1']);

        $summary = $this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);

        $this->assertSame(['incompatible' => 2, 'unknown' => 1, 'total' => 4, 'pinned' => 0], $summary);
    }

    public function test_everything_compatible_counts_zero(): void
    {
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1']);

        $this->assertSame(['incompatible' => 0, 'unknown' => 0, 'total' => 1, 'pinned' => 0], $this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
    }

    public function test_modrinth_unreachable_counts_nothing_as_incompatible(): void
    {
        Http::fake(['api.modrinth.com/*' => Http::failedConnection()]);
        $this->install(['gone' => 'g1', 'old' => 'old1']);

        $this->assertSame(['incompatible' => 0, 'unknown' => 2, 'total' => 2, 'pinned' => 0], $this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
    }

    public function test_second_call_is_served_from_cache(): void
    {
        $this->fakeModrinth();
        $this->install(['gone' => 'g1', 'old' => 'old1']);
        $service = $this->service();

        $first = $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);
        // Drop the per-project version cache too, so only the summary cache can save the request.
        cache()->forget('modrinth_versions:gone:26.3:fabric');
        cache()->forget('modrinth_versions:old:26.3:fabric');
        $second = $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);

        $this->assertSame($first, $second);
        Http::assertSentCount(2);
    }

    public function test_changed_minecraft_version_misses_the_cache(): void
    {
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1']);
        $service = $this->service();

        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);
        $this->minecraftVersion = '26.4';
        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '"26.4"'));
    }

    public function test_changed_loader_misses_the_cache(): void
    {
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1']);
        $service = $this->service();

        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);
        $this->loader = 'quilt';
        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);

        Http::assertSentCount(2);
    }

    public function test_install_update_or_uninstall_misses_the_cache(): void
    {
        $this->fakeModrinth();
        $service = $this->service();

        $this->install(['old' => 'old1']);
        $this->assertSame(1, $this->incompatible($service));

        // update: old1 -> old2 (the versions of "old" are cached per project, so no new request)
        $this->install(['old' => 'old2']);
        $this->assertSame(0, $this->incompatible($service));

        // install
        $this->install(['old' => 'old2', 'gone' => 'g1']);
        $this->assertSame(['incompatible' => 1, 'unknown' => 0, 'total' => 2, 'pinned' => 0], $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));

        // uninstall
        $this->install(['old' => 'old2']);
        $this->assertSame(['incompatible' => 0, 'unknown' => 0, 'total' => 1, 'pinned' => 0], $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
    }

    public function test_cache_is_per_server_and_project_type(): void
    {
        $this->fakeModrinth();
        $this->install(['gone' => 'g1']);
        $service = $this->service();

        $service->getCompatibilitySummary($this->server(1), ModrinthProjectType::Mod);
        cache()->forget('modrinth_versions:gone:26.3:fabric');
        $service->getCompatibilitySummary($this->server(2), ModrinthProjectType::Mod);
        cache()->forget('modrinth_versions:gone:26.3:fabric');
        $service->getCompatibilitySummary($this->server(1), ModrinthProjectType::Plugin);

        Http::assertSentCount(3);
    }

    public function test_forget_drops_the_cached_summary(): void
    {
        $this->fakeModrinth();
        $this->install(['gone' => 'g1']);
        $service = $this->service();

        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);
        cache()->forget('modrinth_versions:gone:26.3:fabric');
        MinecraftModrinthService::forgetCompatibilitySummary($this->server(), ModrinthProjectType::Mod);
        $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod);

        Http::assertSentCount(2);
    }

    public function test_unknown_results_are_cached_only_briefly(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/gone/*' => Http::sequence()
                ->push('oops', 500)
                ->push([]),
        ]);
        $this->install(['gone' => 'g1']);
        $service = $this->service();

        $this->assertSame(0, $this->incompatible($service));
        $this->assertSame(0, $this->incompatible($service));
        Http::assertSentCount(1);

        $this->travel(6)->minutes();

        $this->assertSame(1, $this->incompatible($service));
        Http::assertSentCount(2);
    }

    public function test_complete_results_expire_after_the_ttl(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/gone/*' => Http::sequence()
                ->push([])
                ->push([['id' => 'g1', 'version_number' => '1', 'date_published' => '2026-01-01']]),
        ]);
        $this->install(['gone' => 'g1']);
        $service = $this->service();

        $this->assertSame(1, $this->incompatible($service));

        $this->travel(5)->hours();
        $this->assertSame(1, $this->incompatible($service));

        $this->travel(2)->hours();
        $this->assertSame(0, $this->incompatible($service));
        Http::assertSentCount(2);
    }

    public function test_unknown_loader_returns_null_without_asking_modrinth(): void
    {
        Http::fake();
        $this->loader = null;
        $this->install(['gone' => 'g1']);

        $this->assertNull($this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
        Http::assertNothingSent();
    }

    public function test_unreadable_metadata_returns_null(): void
    {
        Http::fake();
        $this->installed = null;

        $this->assertNull($this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
        Http::assertNothingSent();
    }

    public function test_nothing_installed_counts_zero_without_asking_modrinth(): void
    {
        Http::fake();
        $this->install([]);

        $this->assertSame(['incompatible' => 0, 'unknown' => 0, 'total' => 0, 'pinned' => 0], $this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
        Http::assertNothingSent();
    }

    /** Plan 18: pinned projects still count; `pinned` says how many of them are pinned. */
    public function test_pinned_projects_still_count_and_are_counted_separately(): void
    {
        $this->fakeModrinth();
        $this->install(['ok' => 'ok1', 'old' => 'old1', 'gone' => 'g1', 'broken' => 'b1']);
        foreach ($this->installed as $index => $mod) {
            if (in_array($mod['project_id'], ['ok', 'old', 'broken'], true)) {
                $this->installed[$index]['pinned'] = true;
            }
        }

        // Only "old" is both pinned and a problem: "ok" is compatible, "broken" unknown.
        $this->assertSame(['incompatible' => 2, 'unknown' => 1, 'total' => 4, 'pinned' => 1], $this->service()->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
    }

    public function test_pinning_or_unpinning_misses_the_cache(): void
    {
        $this->fakeModrinth();
        $service = $this->service();
        $this->install(['gone' => 'g1']);

        $this->assertSame(['incompatible' => 1, 'unknown' => 0, 'total' => 1, 'pinned' => 0], $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));

        $this->installed[0]['pinned'] = true;
        $this->assertSame(['incompatible' => 1, 'unknown' => 0, 'total' => 1, 'pinned' => 1], $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));

        unset($this->installed[0]['pinned']);
        $this->assertSame(['incompatible' => 1, 'unknown' => 0, 'total' => 1, 'pinned' => 0], $service->getCompatibilitySummary($this->server(), ModrinthProjectType::Mod));
    }
}
