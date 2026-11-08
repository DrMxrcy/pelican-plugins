<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 4: an installed client-only project (server_side = unsupported, e.g. Sodium) is
 * flagged in the Installed tab and cannot be updated, only uninstalled. Nothing on disk or
 * in the metadata changes unless the user uninstalls.
 */
class ClientOnlyInstalledTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Installed tab rows, sorted by title: Gone, Lithium, Sodium. */
    private const GONE_ROW = '0';

    private const LITHIUM_ROW = '1';

    private const SODIUM_ROW = '2';

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
        // Every project has a newer version (v2) than the installed one (v1).
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([$this->latestVersion()]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(
            fn (array $ids) => array_fill_keys($ids, [$this->latestVersion()]),
        );
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([
            $this->metadata('sodium', 'Sodium'),
            $this->metadata('lithium', 'Lithium'),
            $this->metadata('gone', 'Gone'),
        ]);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([
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
            $this->project('lithium', 'optional', 'optional'),
            $this->project('sodium', 'unsupported', 'required'),
        ]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [$this->project('sodium', 'unsupported', 'required')],
            'total_hits' => 1,
        ]);

        // Fails if: anything is deleted or metadata changed without the user uninstalling.
        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @return array<string, string> */
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

    /** @return array{id: string, version_number: string, files: array<int, array{primary: bool, filename: string, url: string}>} */
    private function latestVersion(): array
    {
        return [
            'id' => 'v2',
            'version_number' => '2.0.0',
            'files' => [['primary' => true, 'filename' => 'mod-2.0.0.jar', 'url' => 'https://cdn.modrinth.com/mod-2.0.0.jar']],
        ];
    }

    /** @return array<string, mixed> */
    private function project(string $id, string $serverSide, string $clientSide): array
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

    private function page(string $tab = 'installed'): Testable
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

    private function updateAction(Testable $page): Action
    {
        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction('update');
        $this->assertNotNull($action);

        return $action;
    }

    public function test_client_only_row_is_badged_and_cannot_be_updated(): void
    {
        $page = $this->page()
            ->assertSee(trans('modrinth-plus::strings.client_only.badge'))
            ->assertActionHidden(TestAction::make('update')->table(self::SODIUM_ROW))
            ->assertActionHidden(TestAction::make('installed')->table(self::SODIUM_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::SODIUM_ROW));

        $description = $this->description($page, self::SODIUM_ROW);
        $this->assertInstanceOf(Htmlable::class, $description);
        $html = $description->toHtml();
        $this->assertStringContainsString('fi-badge', $html);
        $this->assertStringContainsString('fi-color-danger', $html);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.client_only.badge')), $html);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.client_only.badge_tooltip')), $html);
        $this->assertStringContainsString(e('The sodium mod'), $html);
        $this->assertStringNotContainsString(e(trans('modrinth-plus::strings.sides.players_need_it')), $html);
    }

    public function test_update_handler_refuses_client_only_row(): void
    {
        $page = $this->page();
        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();

        // Runs the handler directly, past the hidden button, as a forged request would.
        $action = $this->updateAction($page)->record($instance->getTableRecord(self::SODIUM_ROW));

        try {
            $action->call();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('The update handler ran for a client-only project.');
    }

    public function test_rows_with_server_side_support_are_unchanged(): void
    {
        $page = $this->page()
            ->assertActionVisible(TestAction::make('update')->table(self::LITHIUM_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::LITHIUM_ROW));

        $this->assertSame('The lithium mod', $this->description($page, self::LITHIUM_ROW));
    }

    public function test_unavailable_row_has_no_badge(): void
    {
        $page = $this->page()->assertSee('Gone');

        $this->assertSame('This project is no longer available on Modrinth.', $this->description($page, self::GONE_ROW));
    }

    public function test_all_tab_does_not_show_client_only_badge(): void
    {
        $page = $this->page('all')
            ->assertDontSee(trans('modrinth-plus::strings.client_only.badge'));

        $this->assertSame('The sodium mod', $this->description($page, '0'));
    }

    public function test_badge_is_translated_to_german(): void
    {
        app()->setLocale('de');

        $this->assertNotSame(
            trans('modrinth-plus::strings.client_only.badge', locale: 'en'),
            trans('modrinth-plus::strings.client_only.badge'),
        );

        $this->page()->assertSee(trans('modrinth-plus::strings.client_only.badge'));
    }
}
