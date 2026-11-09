<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 3: rows of projects players must install too (client_side = required, not
 * client-only) carry a badge under the title, in both the All and Installed tabs.
 */
class PlayersNeedItBadgeTest extends TestCase
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

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([
            $this->metadata('xaeros-minimap', "Xaero's Minimap"),
            $this->metadata('gone', 'Gone'),
            $this->metadata('lithium', 'Lithium'),
        ]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [
                $this->hit('xaeros-minimap', 'optional', 'required'),
                $this->hit('architectury-api', 'required', 'required'),
                $this->hit('lithium', 'optional', 'optional'),
                $this->hit('fabric-api', 'optional', 'optional'),
                $this->hit('sodium', 'unsupported', 'required'),
            ],
            'total_hits' => 5,
            'versions' => [],
        ]);
        // Same shape as MinecraftModrinthService::getInstalledModsFromModrinth(): /v2/projects
        // entries, plus a not-on-Modrinth row that has no client_side/server_side keys.
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([
            $this->hit('xaeros-minimap', 'optional', 'required'),
            [
                'project_id' => 'gone',
                'slug' => 'gone',
                'title' => 'Gone',
                'description' => 'This project is no longer available on Modrinth.',
                'icon_url' => null,
                'author' => '',
                'downloads' => 0,
                'date_modified' => '2026-01-01T00:00:00+00:00',
                'project_type' => '',
                'unavailable' => true,
            ],
            $this->hit('lithium', 'optional', 'optional'),
        ]);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string} */
    private function metadata(string $id, string $title): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $title,
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => "$id-1.0.0.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ];
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

    private function page(string $tab = 'all'): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', $tab)
            ->loadTable();
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

    private function assertBadge(Testable $page, string $rowKey, string $description): void
    {
        $actual = $this->description($page, $rowKey);

        $this->assertInstanceOf(Htmlable::class, $actual, "Row [$rowKey] has no badge");
        $html = $actual->toHtml();
        $this->assertStringContainsString('fi-badge', $html);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.sides.players_need_it')), $html);
        // The tooltip goes into an Alpine expression, so it is JS-encoded (an apostrophe becomes \u0027).
        $this->assertStringContainsString(Js::from(trans('modrinth-plus::strings.sides.players_need_it_tooltip'))->toHtml(), $html);
        $this->assertStringContainsString(e($description), $html);
    }

    private function assertNoBadge(Testable $page, string $rowKey, string $description): void
    {
        $this->assertSame($description, $this->description($page, $rowKey), "Row [$rowKey] should have no badge");
    }

    public function test_all_tab_badges_projects_players_must_install(): void
    {
        $page = $this->page()
            ->assertSee(trans('modrinth-plus::strings.sides.players_need_it'));

        $this->assertBadge($page, '0', 'The xaeros-minimap mod');
        $this->assertBadge($page, '1', 'The architectury-api mod');
        $this->assertNoBadge($page, '2', 'The lithium mod');
        $this->assertNoBadge($page, '3', 'The fabric-api mod');
        $this->assertNoBadge($page, '4', 'The sodium mod');
    }

    public function test_installed_tab_badges_projects_and_skips_unavailable_rows(): void
    {
        $page = $this->page('installed')
            ->assertSee(trans('modrinth-plus::strings.sides.players_need_it'))
            ->assertSee('Gone');

        $this->assertBadge($page, '0', 'The xaeros-minimap mod');
        $this->assertNoBadge($page, '1', 'This project is no longer available on Modrinth.');
        $this->assertNoBadge($page, '2', 'The lithium mod');
    }

    public function test_badge_is_translated_to_german(): void
    {
        app()->setLocale('de');

        $this->assertNotSame(
            trans('modrinth-plus::strings.sides.players_need_it', locale: 'en'),
            trans('modrinth-plus::strings.sides.players_need_it'),
        );

        $page = $this->page()
            ->assertSee(trans('modrinth-plus::strings.sides.players_need_it'));

        $this->assertBadge($page, '0', 'The xaeros-minimap mod');
    }
}
