<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Enums\ModrinthSearchIndex;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

/** Plan 8: the All tab's sort (Modrinth `index`) and category filter reach the search request and its cache key. */
class SortAndCategoryFilterTest extends TestCase
{
    private const SERVER_SIDE_GROUP = ['server_side:required', 'server_side:optional', 'server_side:unknown'];

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    private function service(): MinecraftModrinthService
    {
        /** @var MinecraftModrinthService&MockInterface $service */
        $service = Mockery::mock(MinecraftModrinthService::class)->makePartial();
        $service->shouldReceive('getLoaderFromServer')->andReturn([
            'icon' => '',
            'name' => 'fabric',
            'supported_project_types' => ['mod'],
            'display_name' => 'Fabric',
        ]);
        $service->shouldReceive('getMinecraftVersion')->andReturn('26.3');

        return $service;
    }

    /** @return array<string, mixed> query string of the request */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<int, array<int, string>> */
    private function facetsOf(Request $request): array
    {
        $facets = $this->queryOf($request)['facets'] ?? null;

        /** @var array<int, array<int, string>> $decoded */
        $decoded = is_string($facets) ? json_decode($facets, true, flags: JSON_THROW_ON_ERROR) : [];

        return $decoded;
    }

    private function fakeSearch(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::sequence()
            ->push(['hits' => [['slug' => 'first']], 'total_hits' => 1])
            ->push(['hits' => [['slug' => 'second']], 'total_hits' => 1])
            ->push(['hits' => [['slug' => 'third']], 'total_hits' => 1]),
        ]);
    }

    public function test_index_and_categories_are_sent_with_compatibility_facets_kept(): void
    {
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod, index: ModrinthSearchIndex::Downloads, categories: ['optimization']);

        Http::assertSent(function (Request $request) {
            $facets = $this->facetsOf($request);

            return ($this->queryOf($request)['index'] ?? null) === 'downloads'
                && in_array(['categories:optimization'], $facets, true)
                && in_array(['categories:fabric'], $facets, true)
                && in_array(['versions:26.3'], $facets, true)
                && in_array(self::SERVER_SIDE_GROUP, $facets, true);
        });
    }

    public function test_default_index_is_downloads_without_search(): void
    {
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['index'] ?? null) === 'downloads');
    }

    public function test_default_index_is_relevance_with_search(): void
    {
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod, 1, 'sodium');

        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['index'] ?? null) === 'relevance');
    }

    public function test_changing_sort_does_not_serve_the_cached_page(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $downloads = $service->getProjects(new Server(), ModrinthProjectType::Mod, index: ModrinthSearchIndex::Downloads);
        $newest = $service->getProjects(new Server(), ModrinthProjectType::Mod, index: ModrinthSearchIndex::Newest);

        $this->assertSame('first', $downloads['hits'][0]['slug']);
        $this->assertSame('second', $newest['hits'][0]['slug']);
        Http::assertSentCount(2);
    }

    public function test_changing_categories_does_not_serve_the_cached_page(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $none = $service->getProjects(new Server(), ModrinthProjectType::Mod);
        $optimization = $service->getProjects(new Server(), ModrinthProjectType::Mod, categories: ['optimization']);
        $both = $service->getProjects(new Server(), ModrinthProjectType::Mod, categories: ['optimization', 'utility']);

        $this->assertSame(['first', 'second', 'third'], [$none['hits'][0]['slug'], $optimization['hits'][0]['slug'], $both['hits'][0]['slug']]);
        Http::assertSentCount(3);
    }

    public function test_explicit_default_index_and_category_order_share_the_cache(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $service->getProjects(new Server(), ModrinthProjectType::Mod, categories: ['utility', 'optimization']);
        $again = $service->getProjects(new Server(), ModrinthProjectType::Mod, index: ModrinthSearchIndex::Downloads, categories: ['optimization', 'utility']);

        $this->assertSame('first', $again['hits'][0]['slug']);
        Http::assertSentCount(1);
    }
}
