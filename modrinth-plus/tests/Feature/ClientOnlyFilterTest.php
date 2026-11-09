<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;

class ClientOnlyFilterTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const SERVER_SIDE_GROUP = ['server_side:required', 'server_side:optional', 'server_side:unknown'];

    /** @var array<int, bool> includeClientOnly flag of every getProjects() call the page made, in order */
    private array $includeClientOnlyCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
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

    /**
     * Modrinth's server_side facet matches a project when any of its versions allows the
     * server, so a project marked client-only (e.g. Zoomify) can still come back. The hit's
     * own server_side decides.
     */
    public function test_client_only_hits_the_facet_lets_through_are_dropped(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [
            ['slug' => 'zoomify', 'server_side' => 'unsupported', 'client_side' => 'required'],
            ['slug' => 'lithium', 'server_side' => 'optional', 'client_side' => 'optional'],
            ['slug' => 'no-sides'],
        ], 'total_hits' => 3])]);

        $result = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod);

        $this->assertSame(['lithium', 'no-sides'], array_column($result['hits'], 'slug'));
    }

    public function test_client_only_hits_stay_when_showing_client_only(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [
            ['slug' => 'zoomify', 'server_side' => 'unsupported', 'client_side' => 'required'],
        ], 'total_hits' => 1])]);

        $result = $this->service()->getProjects(new Server(), ModrinthProjectType::Mod, 1, null, true);

        $this->assertSame(['zoomify'], array_column($result['hits'], 'slug'));
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

    /**
     * Mods page on the All tab whose search returns Sodium (client-only) and Lithium. The
     * facade is faked, so the hits come back whatever the toggle says.
     */
    private function modsPage(): Testable
    {
        Filament::setCurrentPanel('server');

        [$user, $server] = $this->generateTestAccount();
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturnUsing(function (Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null, bool $includeClientOnly = false) {
            $this->includeClientOnlyCalls[] = $includeClientOnly;

            return [
                'hits' => [$this->hit('sodium', 'unsupported', 'required'), $this->hit('lithium', 'optional', 'optional')],
                'total_hits' => 2,
                'versions' => [],
            ];
        });

        return Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable();
    }

    /** @return array<string, mixed> */
    private function hit(string $id, string $serverSide, string $clientSide): array
    {
        return [
            'project_id' => $id,
            'slug' => $id,
            'title' => ucfirst($id),
            'description' => "The $id mod",
            'author' => 'someone',
            'downloads' => 10,
            'date_modified' => '2026-01-01T00:00:00Z',
            'icon_url' => null,
            'project_type' => 'mod',
            'server_side' => $serverSide,
            'client_side' => $clientSide,
        ];
    }

    /** Turns "Include client-only mods" on, the way the user does. */
    private function includeClientOnly(Testable $page): Testable
    {
        return $page->callAction(TestAction::make('toggle_client_only')->table());
    }

    private function description(Testable $page, string $rowKey): string|Htmlable|null
    {
        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();

        $column = $instance->getTable()->getColumn('title');
        $this->assertInstanceOf(TextColumn::class, $column);

        $column->record($instance->getTableRecord($rowKey));

        return $column->getDescriptionBelow();
    }

    /** Plan 37: with client-only mods shown, Sodium on the All tab must be told apart from server mods. */
    public function test_all_tab_badges_client_only_hits_when_they_are_shown(): void
    {
        $page = $this->includeClientOnly($this->modsPage());

        $this->assertSame(true, end($this->includeClientOnlyCalls));

        $description = $this->description($page, '0');
        $this->assertInstanceOf(Htmlable::class, $description, 'Sodium has no badge on the All tab');
        $html = $description->toHtml();
        $this->assertStringContainsString('fi-color-danger', $html);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.client_only.search_badge')), $html);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.client_only.search_badge_tooltip')), $html);
        $this->assertStringContainsString(e('The sodium mod'), $html);
        // The Installed-tab wording ("safe to remove") does not belong on a search result.
        $this->assertStringNotContainsString(e(trans('modrinth-plus::strings.client_only.badge')), $html);

        // Server mods stay unbadged.
        $this->assertSame('The lithium mod', $this->description($page, '1'));
    }

    public function test_all_tab_badges_client_only_hits_when_the_config_shows_them(): void
    {
        config()->set('modrinth-plus.hide_client_only', false);

        $description = $this->description($this->modsPage(), '0');

        $this->assertInstanceOf(Htmlable::class, $description);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.client_only.search_badge')), $description->toHtml());
    }

    /** Fails if: client-only mods become visible by default. */
    public function test_client_only_mods_stay_hidden_by_default(): void
    {
        $this->modsPage();

        $this->assertNotEmpty($this->includeClientOnlyCalls);
        $this->assertNotContains(true, $this->includeClientOnlyCalls);
    }
}
