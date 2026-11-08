<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;

/**
 * Plan 5: getProjects() already looked up every hit's versions to filter the All tab, so the
 * page must reuse them rather than fetching versions again for the visible rows.
 */
class AllTabReusesVersionsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');

        [$user, $server] = $this->generateTestAccount();
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function hit(string $id): array
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
        ];
    }

    public function test_all_tab_reuses_versions_from_the_search_filter(): void
    {
        $newer = ['id' => 'v2', 'version_number' => '2.0.0', 'files' => []];

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([[
            'project_id' => 'alpha',
            'project_slug' => 'alpha',
            'project_title' => 'Alpha',
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => 'alpha-1.0.0.jar',
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [$this->hit('alpha'), $this->hit('beta'), $this->hit('gamma')],
            'total_hits' => 3,
            'versions' => ['alpha' => [$newer], 'beta' => [$newer], 'gamma' => null],
        ]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->never();
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->never();

        $page = Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable();

        $cache = (new ReflectionProperty($page->instance(), 'versionsCache'))->getValue($page->instance());

        $this->assertSame(['alpha' => [$newer], 'beta' => [$newer], 'gamma' => []], $cache);
    }
}
