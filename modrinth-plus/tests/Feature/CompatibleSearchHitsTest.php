<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

/**
 * Search facets match per project, so a hit can satisfy the loader facet with one build and the
 * version facet with another. getProjects() keeps only hits with a build for both.
 */
class CompatibleSearchHitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    private function service(?string $minecraftVersion = '26.3'): MinecraftModrinthService
    {
        /** @var MinecraftModrinthService&MockInterface $service */
        $service = Mockery::mock(MinecraftModrinthService::class)->makePartial();
        $service->shouldReceive('getLoaderFromServer')->andReturn([
            'icon' => '',
            'name' => 'fabric',
            'supported_project_types' => ['mod'],
            'display_name' => 'Fabric',
        ]);
        $service->shouldReceive('getMinecraftVersion')->andReturn($minecraftVersion);

        return $service;
    }

    /** @param  array<string, mixed>  $versionStubs  project id => fake response */
    private function fakeSearch(array $versionStubs): void
    {
        $hits = array_map(fn (string $id) => ['project_id' => $id, 'slug' => $id], array_keys($versionStubs));

        $stubs = ['api.modrinth.com/v2/search*' => Http::response(['hits' => $hits, 'total_hits' => 42])];
        foreach ($versionStubs as $id => $stub) {
            $stubs["api.modrinth.com/v2/project/$id/*"] = $stub;
        }

        Http::fake($stubs);
    }

    /**
     * @param  array{hits: array<int, array<string, mixed>>}  $response
     * @return array<int, string>
     */
    private function ids(array $response): array
    {
        return array_column($response['hits'], 'project_id');
    }

    /** @return array<string, mixed> */
    private function version(string $id): array
    {
        return ['id' => $id, 'date_published' => '2026-01-01', 'files' => []];
    }

    public function test_hit_without_a_matching_build_is_removed(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'mismatch' => Http::response([]),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['compatible'], $this->ids($response));
    }

    public function test_failed_version_lookup_keeps_the_hit(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'broken' => Http::response('oops', 500),
            'offline' => Http::failedConnection(),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['compatible', 'broken', 'offline'], $this->ids($response));
    }

    public function test_modrinth_outage_does_not_empty_the_list(): void
    {
        $this->fakeSearch([
            'a' => Http::response('down', 503),
            'b' => Http::response('down', 503),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['a', 'b'], $this->ids($response));
    }

    public function test_versions_are_returned_for_reuse(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'broken' => Http::response('oops', 500),
            'mismatch' => Http::response([]),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['compatible', 'broken'], array_keys($response['versions']));
        $this->assertSame('v1', $response['versions']['compatible'][0]['id']);
        $this->assertNull($response['versions']['broken']);
    }

    public function test_version_lookup_filters_by_loader_and_minecraft_version(): void
    {
        $this->fakeSearch(['compatible' => Http::response([$this->version('v1')])]);

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/project/compatible/version')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['loaders'] ?? null) === '["fabric"]' && ($query['game_versions'] ?? null) === '["26.3"]';
        });
    }

    public function test_proxy_server_matches_loader_only(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'mismatch' => Http::response([]),
        ]);

        $response = $this->service(null)->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['compatible'], $this->ids($response));
        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/project/compatible/version')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['loaders'] ?? null) === '["fabric"]' && !array_key_exists('game_versions', $query);
        });
    }

    public function test_total_hits_is_left_as_reported_and_page_is_not_refilled(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'mismatch' => Http::response([]),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(42, $response['total_hits']);
        Http::assertSentCount(3); // one search + one version lookup per hit, no second search page
    }

    public function test_hits_are_reindexed(): void
    {
        $this->fakeSearch([
            'mismatch' => Http::response([]),
            'compatible' => Http::response([$this->version('v1')]),
        ]);

        $response = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame([0], array_keys($response['hits']));
    }

    public function test_cached_search_page_is_filtered_against_cached_versions(): void
    {
        $this->fakeSearch([
            'compatible' => Http::response([$this->version('v1')]),
            'mismatch' => Http::response([]),
        ]);
        $service = $this->service();

        $service->getProjects(new Server(), ModrinthProjectType::Mod);
        $again = $service->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['compatible'], $this->ids($again));
        Http::assertSentCount(3);
    }
}
