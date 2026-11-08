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

class ClientOnlyFilterTest extends TestCase
{
    private const SERVER_SIDE_GROUP = ['server_side:required', 'server_side:optional', 'server_side:unknown'];

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    /** Service with the server-derived loader/version stubbed so no database is needed. */
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

    /** @return array<int, array<int, string>>|null facets of the given search request */
    private function facetsOf(Request $request): ?array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (!is_string($query['facets'] ?? null)) {
            return null;
        }

        /** @var array<int, array<int, string>> $facets */
        $facets = json_decode($query['facets'], true, flags: JSON_THROW_ON_ERROR);

        return $facets;
    }

    private function fakeSearch(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::sequence()
            ->push(['hits' => [['slug' => 'first']], 'total_hits' => 1])
            ->push(['hits' => [['slug' => 'second']], 'total_hits' => 1]),
        ]);
    }

    public function test_toggle_strings_exist_in_every_locale(): void
    {
        foreach (['en', 'de'] as $locale) {
            foreach (['show', 'hide', 'tooltip'] as $key) {
                $line = "modrinth-plus::strings.client_only.$key";
                $this->assertNotSame($line, trans($line, locale: $locale), "$locale: $key missing");
            }
        }
    }

    public function test_config_defaults_to_hiding_client_only(): void
    {
        $this->assertTrue(config('modrinth-plus.hide_client_only'));
    }

    public function test_search_hides_client_only_by_default(): void
    {
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        Http::assertSent(fn (Request $request) => in_array(self::SERVER_SIDE_GROUP, $this->facetsOf($request) ?? [], true));
    }

    public function test_include_client_only_drops_server_side_facet(): void
    {
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod, 1, null, true);

        Http::assertSent(fn (Request $request) => !str_contains(json_encode($this->facetsOf($request), JSON_THROW_ON_ERROR), 'server_side'));
    }

    public function test_config_off_drops_server_side_facet(): void
    {
        config()->set('modrinth-plus.hide_client_only', false);
        $this->fakeSearch();

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        Http::assertSent(fn (Request $request) => !str_contains(json_encode($this->facetsOf($request), JSON_THROW_ON_ERROR), 'server_side'));
    }

    public function test_toggling_does_not_serve_the_cached_page(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $hidden = $service->getProjects(new Server(), ModrinthProjectType::Mod, 1, 'iris');
        $shown = $service->getProjects(new Server(), ModrinthProjectType::Mod, 1, 'iris', true);

        $this->assertSame('first', $hidden['hits'][0]['slug']);
        $this->assertSame('second', $shown['hits'][0]['slug']);
        Http::assertSentCount(2);
    }

    public function test_toggling_config_does_not_serve_the_cached_page(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $hidden = $service->getProjects(new Server(), ModrinthProjectType::Mod);
        config()->set('modrinth-plus.hide_client_only', false);
        $shown = $service->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame('first', $hidden['hits'][0]['slug']);
        $this->assertSame('second', $shown['hits'][0]['slug']);
        Http::assertSentCount(2);
    }

    public function test_same_filter_is_served_from_cache(): void
    {
        $this->fakeSearch();
        $service = $this->service();

        $service->getProjects(new Server(), ModrinthProjectType::Mod);
        $again = $service->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame('first', $again['hits'][0]['slug']);
        Http::assertSentCount(1);
    }
}
