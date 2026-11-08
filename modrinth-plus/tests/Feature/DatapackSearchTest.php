<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

/**
 * Plan 12: datapack search and version lookups use Modrinth's `datapack` loader and the
 * `project_type:datapack` facet, whatever loader the egg runs; mods/plugins keep the egg's.
 */
class DatapackSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
        config()->set('modrinth-plus.hide_client_only', true);

        Http::fake([
            'api.modrinth.com/v2/tag/loader' => Http::response([
                ['icon' => '<svg>fabric</svg>', 'name' => 'fabric', 'supported_project_types' => ['mod', 'modpack']],
                ['icon' => '<svg>datapack</svg>', 'name' => 'datapack', 'supported_project_types' => ['datapack', 'project', 'mod']],
                ['icon' => '<svg>paper</svg>', 'name' => 'paper', 'supported_project_types' => ['plugin']],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([
                ['name' => 'worldgen', 'project_type' => 'mod', 'header' => 'categories', 'icon' => ''],
                ['name' => 'adventure', 'project_type' => 'mod', 'header' => 'categories', 'icon' => ''],
                ['name' => 'economy', 'project_type' => 'plugin', 'header' => 'categories', 'icon' => ''],
            ]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [['project_id' => 'terralith', 'slug' => 'terralith', 'project_type' => 'mod']], 'total_hits' => 1]),
            'api.modrinth.com/v2/project/terralith/version*' => Http::response([['id' => 'v1', 'date_published' => '2026-01-01', 'files' => [['primary' => true, 'filename' => 'Terralith.zip', 'url' => 'https://cdn.modrinth.com/Terralith.zip']]]]),
        ]);
    }

    /**
     * @param  array<int, string>  $features
     * @param  array<int, string>  $tags
     */
    private function server(array $features = ['modrinth_mods', 'modrinth_datapacks'], array $tags = ['minecraft', 'fabric']): Server
    {
        $server = new Server();
        $server->id = 1;
        $server->setRelation('egg', (new Egg())->forceFill(['features' => $features, 'tags' => $tags]));

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        /** @var MinecraftModrinthService&MockInterface $service */
        $service = Mockery::mock(MinecraftModrinthService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getMinecraftVersion')->andReturn('26.3');

        return $service;
    }

    /** @return array<int, Request> */
    private function requestsTo(string $path): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), "api.modrinth.com/v2/$path"))->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_datapack_loader_comes_from_the_feature_not_the_egg_loader(): void
    {
        $loader = $this->service()->getLoaderFromServer($this->server(), ModrinthProjectType::Datapack);

        $this->assertNotNull($loader);
        $this->assertSame('datapack', $loader['name']);
        $this->assertSame('<svg>datapack</svg>', $loader['icon']);
        $this->assertSame('Datapack', $loader['display_name']);
    }

    public function test_no_datapack_loader_without_the_feature_or_minecraft_tag(): void
    {
        $this->assertNull($this->service()->getLoaderFromServer($this->server(['modrinth_mods']), ModrinthProjectType::Datapack));
        $this->assertNull($this->service()->getLoaderFromServer($this->server(tags: ['fabric']), ModrinthProjectType::Datapack));
    }

    public function test_mod_loader_is_unchanged_by_the_datapack_feature(): void
    {
        $service = $this->service();

        $this->assertSame('fabric', $service->getLoaderFromServer($this->server())['name'] ?? null);
        $this->assertSame('fabric', $service->getLoaderFromServer($this->server(), ModrinthProjectType::Mod)['name'] ?? null);
        $this->assertSame($service->getLoaderFromServer($this->server(['modrinth_mods'])), $service->getLoaderFromServer($this->server()));
    }

    public function test_vanilla_egg_with_only_datapacks_has_no_mod_loader(): void
    {
        $this->assertNull($this->service()->getLoaderFromServer($this->server(['modrinth_datapacks'], ['minecraft', 'vanilla'])));
    }

    public function test_datapack_search_uses_datapack_facets_and_no_server_side_filter(): void
    {
        $response = $this->service()->getProjects($this->server(), ModrinthProjectType::Datapack, categories: ['worldgen']);

        $this->assertSame(['terralith'], array_column($response['hits'], 'project_id'));

        $search = $this->requestsTo('search');
        $this->assertCount(1, $search);
        $this->assertSame(
            [['categories:datapack'], ['versions:26.3'], ['project_type:datapack'], ['categories:worldgen']],
            json_decode($search[0]->data()['facets'], true),
        );
    }

    public function test_datapack_versions_query_uses_the_datapack_loader(): void
    {
        $this->service()->getProjects($this->server(), ModrinthProjectType::Datapack);

        $versions = $this->requestsTo('project/terralith/version');
        $this->assertCount(1, $versions);
        $this->assertSame('["datapack"]', $versions[0]->data()['loaders']);
        $this->assertSame('["26.3"]', $versions[0]->data()['game_versions']);
    }

    public function test_single_and_bulk_version_lookups_use_the_datapack_loader(): void
    {
        $service = $this->service();

        $this->assertSame('v1', $service->getProjectVersions('terralith', $this->server(), ModrinthProjectType::Datapack)[0]['id']);
        cache()->flush();
        $this->assertSame('v1', $service->getProjectVersionsBulk(['terralith'], $this->server(), ModrinthProjectType::Datapack)['terralith'][0]['id'] ?? null);

        $loaders = array_map(fn (Request $request) => $request->data()['loaders'], $this->requestsTo('project/terralith/version'));
        $this->assertSame(['["datapack"]', '["datapack"]'], $loaders);
    }

    public function test_mod_search_and_versions_still_use_the_egg_loader(): void
    {
        $this->service()->getProjects($this->server(), ModrinthProjectType::Mod);

        $facets = json_decode($this->requestsTo('search')[0]->data()['facets'], true);
        $this->assertSame(['categories:fabric'], $facets[0]);
        $this->assertContains(['project_type:mod', 'project_type:plugin'], $facets);
        $this->assertContains(['server_side:required', 'server_side:optional', 'server_side:unknown'], $facets);

        $this->assertSame('["fabric"]', $this->requestsTo('project/terralith/version')[0]->data()['loaders']);
    }

    public function test_datapack_and_mod_version_lists_are_cached_separately(): void
    {
        $service = $this->service();

        $service->getProjectVersions('terralith', $this->server(), ModrinthProjectType::Mod);
        $service->getProjectVersions('terralith', $this->server(), ModrinthProjectType::Datapack);

        $this->assertCount(2, $this->requestsTo('project/terralith/version'));
    }

    public function test_datapack_categories_are_the_mod_categories(): void
    {
        $this->assertSame(['adventure', 'worldgen'], $this->service()->getCategories(ModrinthProjectType::Datapack));
        $this->assertSame(['economy'], $this->service()->getCategories(ModrinthProjectType::Plugin));
    }
}
