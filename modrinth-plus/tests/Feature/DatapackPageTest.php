<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Exceptions\ServerPropertiesUnreadableException;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthDatapackPage;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthPluginPage;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthProjectPage;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 12: the Datapacks page appears only with the `modrinth_datapacks` egg feature, installs
 * into `<level-name>/datapacks` with the datapack loader, keeps the subuser permission checks,
 * and leaves the Mods/Plugins pages as they were.
 */
class DatapackPageTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const INSTALLED_ROW = '0';

    private const NOT_INSTALLED_ROW = '1';

    private int $folderLookups = 0;

    /** @var array<int, ModrinthProjectType|null> project type of every getProjects/getProjectVersions(Bulk) call */
    private array $lookupTypes = [];

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

    /**
     * @param  array<int, string>  $features
     * @param  array<int, SubuserPermission>  $permissions  empty = the server owner
     */
    private function account(array $features, array $permissions = []): Server
    {
        [$user, $server] = $this->generateTestAccount($permissions);

        $server->egg->forceFill(['features' => $features])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
    }

    /** @var array<int, array<string, mixed>> what getInstalledModsFromModrinth() returns (the Installed tab rows) */
    private array $installedProjects = [];

    /** @var array<int, array<string, mixed>> the world folder listing */
    private array $folderListing = [];

    private function fakeModrinth(string|Exception $folder = 'survival/datapacks'): void
    {
        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('getProjectFolder')->andReturnUsing(function (Server $server, ModrinthProjectType $type) use ($folder) {
            $this->assertSame(ModrinthProjectType::Datapack, $type);
            $this->folderLookups++;

            if ($folder instanceof Exception) {
                throw $folder;
            }

            return $folder;
        });
        MinecraftModrinth::shouldReceive('listFolder')->andReturnUsing(fn () => $this->folderListing);
        $installed = [[
            'project_id' => 'terralith',
            'project_slug' => 'terralith',
            'project_title' => 'Terralith',
            'version_id' => 'v1',
            'version_number' => '2.5.7',
            'filename' => 'Terralith_v2.5.7.zip',
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]];
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn($installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturn($installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(fn () => $this->installedProjects);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturnUsing(function (Server $server, ModrinthProjectType $type) {
            $this->lookupTypes[] = $type;

            return [
                'hits' => [$this->hit('terralith'), $this->hit('incendium')],
                'total_hits' => 2,
                'versions' => [],
            ];
        });
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturnUsing(function (string $id, Server $server, ?ModrinthProjectType $type = null) {
            $this->lookupTypes[] = $type;

            return [$this->latestVersion()];
        });
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(function (array $ids, Server $server, ?ModrinthProjectType $type = null) {
            $this->lookupTypes[] = $type;

            return [];
        });
    }

    /** @return array{id: string, version_number: string, dependencies: array<mixed>, files: array<int, array{primary: bool, filename: string, url: string}>} */
    private function latestVersion(): array
    {
        return [
            'id' => 'v2',
            'version_number' => '2.5.8',
            'dependencies' => [],
            'files' => [['primary' => true, 'filename' => 'Terralith_v2.5.8.zip', 'url' => 'https://cdn.modrinth.com/Terralith_v2.5.8.zip']],
        ];
    }

    /** @return array<string, mixed> */
    private function hit(string $id): array
    {
        return [
            'project_id' => $id,
            'slug' => $id,
            'title' => ucfirst($id),
            'description' => "The $id datapack",
            'author' => 'someone',
            'downloads' => 10,
            'date_modified' => '2026-01-01T00:00:00Z',
            'icon_url' => null,
            // Many datapacks are mods too; Modrinth reports the primary type.
            'project_type' => 'mod',
            // On the Mods page these sides hide the update action and add a badge.
            'client_side' => 'required',
            'server_side' => 'unsupported',
        ];
    }

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthDatapackPage::class)->set('activeTab', 'all')->loadTable();
    }

    private function tableAction(Testable $page, string $name): Action
    {
        /** @var MinecraftModrinthProjectPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction($name);
        $this->assertNotNull($action, "Action [$name] not found");

        return $action;
    }

    public function test_page_appears_only_with_the_datapack_feature(): void
    {
        $this->account(['modrinth_mods', 'modrinth_plugins']);

        $this->assertFalse(MinecraftModrinthDatapackPage::canAccess());
        Livewire::test(MinecraftModrinthDatapackPage::class)->assertForbidden();
    }

    public function test_datapack_feature_alone_shows_only_the_datapack_page(): void
    {
        $this->account(['modrinth_datapacks']);

        $this->assertTrue(MinecraftModrinthDatapackPage::canAccess());
        $this->assertFalse(MinecraftModrinthModPage::canAccess());
        $this->assertFalse(MinecraftModrinthPluginPage::canAccess());
    }

    public function test_mods_page_is_unchanged_next_to_datapacks(): void
    {
        $this->account(['modrinth_mods', 'modrinth_datapacks']);
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('downloadFile')->once()->with(Mockery::type(Server::class), Mockery::any(), 'mods', 'Terralith_v2.5.8.zip');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->withArgs(fn (Server $server, ModrinthProjectType $type) => $type === ModrinthProjectType::Mod)->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'Terralith_v2.5.7.zip');

        $this->assertTrue(MinecraftModrinthModPage::canAccess());

        $page = Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable()
            // Client-only rules still apply on the Mods page.
            ->assertActionHidden(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('toggle_client_only')->table());

        $this->tableAction($page, 'install_latest')->record($this->hit('terralith'))->call();

        // Never resolves a world folder.
        $this->assertSame(0, $this->folderLookups);
        $this->assertNotContains(ModrinthProjectType::Datapack, $this->lookupTypes);
    }

    public function test_install_uses_the_datapack_type_and_world_folder(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('downloadFile')->once()->with(Mockery::type(Server::class), 'https://cdn.modrinth.com/Terralith_v2.5.8.zip', 'survival/datapacks', 'Terralith_v2.5.8.zip');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), ModrinthProjectType::Datapack, 'incendium', 'incendium', 'Incendium', 'v2', '2.5.8', 'Terralith_v2.5.8.zip', 'someone')
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        $this->page()->callAction(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW));

        $this->assertNotEmpty($this->lookupTypes);
        $this->assertSame([ModrinthProjectType::Datapack], array_values(array_unique($this->lookupTypes, SORT_REGULAR)));
    }

    public function test_update_replaces_the_zip_in_the_world_folder(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth('world/datapacks');

        MinecraftModrinth::shouldReceive('downloadFile')->once()->with(Mockery::type(Server::class), Mockery::any(), 'world/datapacks', 'Terralith_v2.5.8.zip');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->withArgs(fn (Server $server, ModrinthProjectType $type) => $type === ModrinthProjectType::Datapack)->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'world/datapacks', 'Terralith_v2.5.7.zip');

        // server_side "unsupported" must not hide the update of a datapack.
        $this->page()->callAction(TestAction::make('update')->table(self::INSTALLED_ROW));
    }

    public function test_uninstall_removes_the_zip_from_the_world_folder(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'survival/datapacks', 'Terralith_v2.5.7.zip');
        MinecraftModrinth::shouldReceive('removeModMetadata')->once()->with(Mockery::type(Server::class), ModrinthProjectType::Datapack, 'terralith')->andReturn(true);

        $this->page()->callAction(TestAction::make('uninstall')->table(self::INSTALLED_ROW));
    }

    public function test_world_folder_is_resolved_once_per_request(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();

        $page = $this->page();
        $this->folderLookups = 0;

        $page->call('$refresh');

        $this->assertSame(1, $this->folderLookups);
    }

    public function test_no_client_only_toggle_or_side_badges(): void
    {
        config()->set('modrinth-plus.hide_client_only', true);
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();

        $page = $this->page()->assertActionHidden(TestAction::make('toggle_client_only')->table());

        /** @var MinecraftModrinthDatapackPage $instance */
        $instance = $page->instance();
        $column = $instance->getTable()->getColumn('title');
        $this->assertInstanceOf(TextColumn::class, $column);

        $column->record($instance->getTableRecord(self::NOT_INSTALLED_ROW));
        $this->assertSame('The incendium datapack', $column->getDescriptionBelow());
    }

    public function test_subuser_with_file_read_only_cannot_install_update_or_uninstall(): void
    {
        $this->account(['modrinth_datapacks'], [SubuserPermission::FileRead]);
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();

        $this->assertTrue(MinecraftModrinthDatapackPage::canAccess());

        $page = $this->page()
            ->assertActionHidden(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('uninstall')->table(self::INSTALLED_ROW));

        foreach (['install_latest' => 'incendium', 'update' => 'terralith', 'uninstall' => 'terralith'] as $name => $id) {
            try {
                $this->tableAction($page, $name)->record($this->hit($id))->call();
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());

                continue;
            }

            $this->fail("The [$name] handler ran without the required file permission.");
        }
    }

    public function test_unreadable_server_properties_refuses_every_write(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth(new ServerPropertiesUnreadableException('daemon unreachable'));

        // Fails if: a daemon error falls back to "world" and touches files there.
        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();

        // The page still renders; it just has no folder link to a guessed world.
        $page = $this->page()->assertActionHidden('open_folder');

        foreach (['install_latest' => self::NOT_INSTALLED_ROW, 'update' => self::INSTALLED_ROW, 'uninstall' => self::INSTALLED_ROW] as $name => $row) {
            $page->callAction(TestAction::make($name)->table($row))
                ->assertNotified(trans('modrinth-plus::strings.datapacks.world_unknown'));
        }
    }

    public function test_unreadable_server_properties_refuses_update_all(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth(new ServerPropertiesUnreadableException('daemon unreachable'));
        $this->installedProjects = [$this->hit('terralith')];

        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();

        $page = $this->page()->set('activeTab', 'installed')->loadTable()
            ->assertActionVisible(TestAction::make('update_all')->table());

        /** @var MinecraftModrinthDatapackPage $instance */
        $instance = $page->instance();
        $instance->getTable()->getAction('update_all')?->call();

        $page->assertNotified(trans('modrinth-plus::strings.datapacks.world_unknown'));
    }

    public function test_update_all_updates_zips_in_the_world_folder_and_ignores_sides(): void
    {
        $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();
        // server_side "unsupported" would make it client-only on the Mods page.
        $this->installedProjects = [$this->hit('terralith')];

        MinecraftModrinth::shouldReceive('downloadFile')->once()->with(Mockery::type(Server::class), Mockery::any(), 'survival/datapacks', 'Terralith_v2.5.8.zip');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->withArgs(fn (Server $server, ModrinthProjectType $type) => $type === ModrinthProjectType::Datapack)->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'survival/datapacks', 'Terralith_v2.5.7.zip');

        $this->page()->set('activeTab', 'installed')->loadTable()
            ->assertActionHasLabel(TestAction::make('update_all')->table(), trans('modrinth-plus::strings.update_all.action', ['count' => 1]))
            ->callAction(TestAction::make('update_all')->table())
            ->assertNotified(trans('modrinth-plus::strings.update_all.success_title'));
    }

    public function test_no_scan_or_not_on_modrinth_rows_on_the_datapack_page(): void
    {
        $server = $this->account(['modrinth_datapacks']);
        $this->fakeModrinth();
        $this->folderListing = [['name' => 'homemade.jar', 'size' => 14, 'file' => true, 'directory' => false, 'modified' => null]];
        UnmatchedJars::remember($server->id, ModrinthProjectType::Datapack, ['homemade.jar' => 14]);

        $page = $this->page()->set('activeTab', 'installed')->loadTable()
            ->assertActionHidden('scan_folder')
            ->assertDontSee('homemade.jar');

        /** @var MinecraftModrinthDatapackPage $instance */
        $instance = $page->instance();

        // A forged call past the hidden button never scans.
        MinecraftModrinth::shouldReceive('scanAndImport')->never();

        try {
            $instance->getAction('scan_folder')?->call();
            $this->fail('scan_folder ran on the datapack page.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_subuser_without_file_read_cannot_open_the_page(): void
    {
        $this->account(['modrinth_datapacks'], [SubuserPermission::ControlConsole]);

        $this->assertFalse(MinecraftModrinthDatapackPage::canAccess());
    }

    public function test_datapack_strings_exist_in_every_locale(): void
    {
        foreach (['en', 'de'] as $locale) {
            foreach (['minecraft_datapacks', 'datapacks.update_heading', 'datapacks.uninstall_heading', 'datapacks.world_unknown', 'datapacks.world_unknown_body'] as $key) {
                $line = "modrinth-plus::strings.$key";
                $this->assertNotSame($line, trans($line, locale: $locale), "$locale: $key missing");
            }
        }
    }
}
