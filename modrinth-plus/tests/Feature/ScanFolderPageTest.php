<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 11: the "Scan folder" header action, and "Not on Modrinth" rows in the Installed tab
 * (read-only, uninstall only). Scanning needs file.read-content + file.create + file.update.
 */
class ScanFolderPageTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Installed tab rows: the imported project first, then the jars not on Modrinth. */
    private const IMPORTED_ROW = '0';

    /** "Not on Modrinth" rows are keyed by file name, not position. */
    private const HOMEMADE_ROW = 'file:homemade.jar';

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  array<int, SubuserPermission>  $permissions  empty = the server owner */
    private function account(array $permissions = []): void
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        $this->server = $server;

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(['name' => 'fabric', 'display_name' => 'Fabric', 'icon' => '', 'supported_project_types' => ['mod']]);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([
            ['name' => '.modrinth-metadata.json', 'size' => 100, 'file' => true, 'directory' => false],
            ['name' => 'sodium-0.6.0.jar', 'size' => 12, 'file' => true, 'directory' => false],
            ['name' => 'homemade.jar', 'size' => 14, 'file' => true, 'directory' => false, 'modified' => '2026-11-01T00:00:00Z'],
            ['name' => 'unscanned.jar', 'size' => 20, 'file' => true, 'directory' => false],
        ]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([[
            'project_id' => 'AANobbMI',
            'project_slug' => 'sodium',
            'project_title' => 'Sodium',
            'version_id' => 'sod6',
            'version_number' => '0.6.0',
            'filename' => 'sodium-0.6.0.jar',
            'installed_at' => '2026-11-08T00:00:00+00:00',
            'imported' => true,
        ]]);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([[
            'project_id' => 'AANobbMI',
            'slug' => 'sodium',
            'title' => 'Sodium',
            'description' => 'Fast rendering',
            'icon_url' => null,
            'author' => '',
            'downloads' => 10,
            'date_modified' => '2026-01-01T00:00:00Z',
            'project_type' => 'mod',
        ]]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([['id' => 'sod7', 'version_number' => '0.7.0', 'files' => []]]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(
            fn (array $ids) => array_fill_keys($ids, [['id' => 'sod7', 'version_number' => '0.7.0', 'files' => []]]),
        );
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn(['hits' => [], 'total_hits' => 0, 'versions' => []]);

        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();

        // The last scan found homemade.jar not on Modrinth; unscanned.jar was added after it.
        UnmatchedJars::remember($server->id, ModrinthProjectType::Mod, ['homemade.jar' => 14]);
    }

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'installed')->loadTable();
    }

    public function test_not_on_modrinth_jars_are_listed_read_only_after_the_installed_projects(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        $page = $this->page()
            ->assertSee('homemade.jar')
            ->assertDontSee('unscanned.jar')
            ->assertSee(trans('modrinth-plus::strings.scan.badge'))
            ->assertActionVisible(TestAction::make('update')->table(self::IMPORTED_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::IMPORTED_ROW))
            ->assertActionHidden(TestAction::make('versions')->table(self::HOMEMADE_ROW))
            ->assertActionHidden(TestAction::make('install_latest')->table(self::HOMEMADE_ROW))
            ->assertActionHidden(TestAction::make('update')->table(self::HOMEMADE_ROW))
            ->assertActionHidden(TestAction::make('installed')->table(self::HOMEMADE_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::HOMEMADE_ROW));

        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $this->assertSame('homemade.jar', $instance->getTableRecord(self::HOMEMADE_ROW)['title']);
        // A positional key (what a shifted list used to resolve to) finds no jar.
        $this->assertNull($instance->getTableRecord('1'));

        $column = $instance->getTable()->getColumn('title');
        $this->assertInstanceOf(TextColumn::class, $column);
        $description = $column->record($instance->getTableRecord(self::HOMEMADE_ROW))->getDescriptionBelow();
        $this->assertInstanceOf(Htmlable::class, $description);
        $this->assertStringContainsString(e(trans('modrinth-plus::strings.scan.badge_tooltip')), $description->toHtml());
    }

    public function test_installed_tab_search_matches_not_on_modrinth_file_names(): void
    {
        $this->account();

        Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'installed')
            ->set('tableSearch', 'homemade')
            ->loadTable()
            ->assertSee('homemade.jar');
    }

    public function test_uninstalling_a_not_on_modrinth_row_deletes_only_that_jar(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'homemade.jar');

        $this->page()->callAction(TestAction::make('uninstall')->table(self::HOMEMADE_ROW));

        $this->assertSame([], UnmatchedJars::remembered($this->server->id, ModrinthProjectType::Mod));
    }

    public function test_uninstall_handler_refuses_a_forged_not_on_modrinth_record(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        /** @var MinecraftModrinthModPage $instance */
        $instance = $this->page()->instance();
        $action = $instance->getTable()->getAction('uninstall');
        $this->assertNotNull($action);

        // A tracked jar and one never scanned must not be deletable through a "Not on Modrinth" record.
        foreach (['sodium-0.6.0.jar', 'unscanned.jar', '../server.properties'] as $filename) {
            $action->record(['project_id' => '', 'title' => $filename, 'filename' => $filename, 'not_on_modrinth' => true])->call();

            // The handler ran and refused (not just an action that was never reached).
            Notification::assertNotified(trans('modrinth-plus::strings.notifications.uninstall_failed'));
        }

        $this->assertSame(['homemade.jar' => 14], UnmatchedJars::remembered($this->server->id, ModrinthProjectType::Mod));
    }

    public function test_scan_runs_the_service_and_reports_the_result(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('scanAndImport')->once()
            ->with(Mockery::type(Server::class), ModrinthProjectType::Mod)
            ->andReturn(['imported' => ['Sodium'], 'not_on_modrinth' => ['homemade.jar'], 'skipped' => [], 'duplicates' => [], 'error' => false]);

        $this->page()
            ->assertActionVisible('scan_folder')
            ->callAction('scan_folder')
            ->assertNotified(trans('modrinth-plus::strings.scan.finished'));
    }

    public function test_scan_failure_is_reported(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('scanAndImport')->once()->andThrow(new Exception('daemon down'));

        $this->page()
            ->callAction('scan_folder')
            ->assertNotified(trans('modrinth-plus::strings.scan.failed'));
    }

    /** @return array<string, array{array<int, SubuserPermission>}> */
    public static function withoutScanPermission(): array
    {
        return [
            'file.read only' => [[SubuserPermission::FileRead]],
            'no file.read-content' => [[SubuserPermission::FileRead, SubuserPermission::FileCreate, SubuserPermission::FileUpdate]],
            'no file.update' => [[SubuserPermission::FileRead, SubuserPermission::FileReadContent, SubuserPermission::FileCreate]],
            'no file.create' => [[SubuserPermission::FileRead, SubuserPermission::FileReadContent, SubuserPermission::FileUpdate]],
        ];
    }

    /**
     * @param  array<int, SubuserPermission>  $permissions
     */
    #[DataProvider('withoutScanPermission')]
    public function test_scan_needs_read_content_create_and_update(array $permissions): void
    {
        $this->account($permissions);
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('scanAndImport')->never();

        $page = $this->page()->assertActionHidden('scan_folder');

        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getAction('scan_folder');
        $this->assertNotNull($action);

        try {
            $action->call();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('The scan handler ran without the required file permissions.');
    }

    public function test_subuser_with_scan_permissions_can_scan(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileReadContent, SubuserPermission::FileCreate, SubuserPermission::FileUpdate]);
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        $this->page()->assertActionVisible('scan_folder');
    }

    public function test_compatibility_check_copes_with_imported_and_not_on_modrinth_jars(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        Livewire::test(MinecraftModrinthModPage::class)
            ->mountAction('check_compatibility')
            ->assertActionMounted('check_compatibility')
            ->assertMountedActionModalSee('Sodium')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.needs_update'))
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.summary', ['count' => 1, 'total' => 1]))
            ->assertMountedActionModalDontSee('homemade.jar');
    }
}
