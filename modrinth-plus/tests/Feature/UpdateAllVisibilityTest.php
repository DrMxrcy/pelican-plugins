<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 18 fix: live panel QA (Fabric, MC 26.3) saw the Xaero's Minimap row offer Update while
 * the "Update all" header action stayed hidden. The page here talks to the real service over a
 * faked Modrinth API, opened the way a browser does: default tab first, then the Installed tab.
 */
class UpdateAllVisibilityTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $installed = [];

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
    private static function metadata(string $id, string $title, string $versionId): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $title,
            'version_id' => $versionId,
            'version_number' => $versionId,
            'filename' => "$versionId.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
            'author' => 'someone',
        ];
    }

    /** @return array<string, mixed> */
    private static function project(string $id, string $title, string $serverSide, string $clientSide): array
    {
        return [
            'id' => $id,
            'slug' => $id,
            'title' => $title,
            'description' => "The $title mod",
            'downloads' => 10,
            'updated' => '2026-01-01T00:00:00Z',
            'icon_url' => null,
            'project_type' => 'mod',
            'server_side' => $serverSide,
            'client_side' => $clientSide,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function versions(string $id, string ...$ids): array
    {
        $versions = [];
        foreach ($ids as $index => $versionId) {
            $versions[] = [
                'id' => $versionId,
                'project_id' => $id,
                'version_number' => $versionId,
                'version_type' => 'release',
                'date_published' => sprintf('2026-02-%02dT00:00:00Z', 20 - $index),
                'changelog' => null,
                'dependencies' => [],
                'files' => [['primary' => true, 'filename' => "$versionId.jar", 'url' => "https://cdn.modrinth.com/$versionId.jar"]],
            ];
        }

        return $versions;
    }

    /**
     * The QA server: Fabric API and Lithium current, Xaero's Minimap (server optional, client
     * required) one version behind and unpinned, plus a jar added by hand.
     */
    private function fakeQaServer(string $fabric = 'fabric-new', bool $projectLookupFails = false): void
    {
        $this->installed = [
            self::metadata('P7dR8mSH', 'Fabric API', $fabric),
            self::metadata('gvQqBUqZ', 'Lithium', 'lithium-new'),
            self::metadata('1bokaNcj', "Xaero's Minimap", 'xaero-old'),
        ];

        $projects = [
            self::project('P7dR8mSH', 'Fabric API', 'required', 'required'),
            self::project('gvQqBUqZ', 'Lithium', 'optional', 'optional'),
            self::project('1bokaNcj', "Xaero's Minimap", 'optional', 'required'),
        ];

        Http::fake([
            'api.modrinth.com/v2/projects*' => $projectLookupFails ? Http::response('timeout', 504) : Http::response($projects),
            'api.modrinth.com/v2/project/P7dR8mSH/version*' => Http::response(self::versions('P7dR8mSH', 'fabric-new', 'fabric-old')),
            'api.modrinth.com/v2/project/gvQqBUqZ/version*' => Http::response(self::versions('gvQqBUqZ', 'lithium-new')),
            'api.modrinth.com/v2/project/1bokaNcj/version*' => Http::response(self::versions('1bokaNcj', 'xaero-new', 'xaero-old')),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
            '*' => Http::response('unexpected request', 500),
        ]);

        MinecraftModrinth::partialMock();
        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(['name' => 'fabric']);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([
            ['name' => 'fabric-new.jar', 'size' => 10, 'modified' => null, 'is_file' => true],
            ['name' => 'lithium-new.jar', 'size' => 10, 'modified' => null, 'is_file' => true],
            ['name' => 'xaero-old.jar', 'size' => 10, 'modified' => null, 'is_file' => true],
            ['name' => 'handmade.jar', 'size' => 10, 'modified' => null, 'is_file' => true],
        ]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnNull();
        MinecraftModrinth::shouldReceive('deleteFile')->andReturnNull();
        MinecraftModrinth::shouldReceive('renameFile')->andReturnNull();
        MinecraftModrinth::shouldReceive('saveModMetadata')->andReturnUsing(
            function (Server $server, ModrinthProjectType $type, string $id, string $slug, string $title, string $versionId, string $versionNumber, string $filename) {
                foreach ($this->installed as $index => $mod) {
                    if ($mod['project_id'] === $id) {
                        $this->installed[$index] = ['version_id' => $versionId, 'version_number' => $versionNumber, 'filename' => $filename] + $mod;
                    }
                }

                return true;
            },
        );
        MinecraftModrinth::shouldReceive('setPinned')->andReturnUsing(function (Server $server, ModrinthProjectType $type, string $id, bool $pinned) {
            foreach ($this->installed as $index => $mod) {
                if ($mod['project_id'] === $id) {
                    unset($this->installed[$index]['pinned']);
                    if ($pinned) {
                        $this->installed[$index]['pinned'] = true;
                    }
                }
            }

            return true;
        });
    }

    public function test_update_all_shows_whenever_an_installed_row_offers_update_after_switching_tabs(): void
    {
        $this->fakeQaServer();

        Livewire::test(MinecraftModrinthModPage::class)
            ->loadTable()
            ->set('activeTab', 'installed')
            ->loadTable()
            ->assertActionVisible(TestAction::make('update')->table('2'))
            ->assertActionVisible(TestAction::make('update_all')->table())
            ->assertActionHasLabel(TestAction::make('update_all')->table(), trans('modrinth-plus::strings.update_all.action', ['count' => 1]));
    }

    /**
     * Root cause: a row whose /v2/projects lookup came back without it (request failed or the
     * project was left out) is marked "unavailable". Its versions still come from the versions
     * lookup, so the row offered Update, while "Update all" dropped every unavailable project
     * before checking for a newer version. Both now use the same rule.
     */
    public function test_update_all_agrees_with_the_row_when_the_project_lookup_fails(): void
    {
        $this->fakeQaServer(projectLookupFails: true);

        Livewire::test(MinecraftModrinthModPage::class)
            ->loadTable()
            ->set('activeTab', 'installed')
            ->loadTable()
            ->assertActionVisible(TestAction::make('update')->table('2'))
            ->assertActionHidden(TestAction::make('update')->table('0'))
            ->assertActionVisible(TestAction::make('update_all')->table())
            ->assertActionHasLabel(TestAction::make('update_all')->table(), trans('modrinth-plus::strings.update_all.action', ['count' => 1]));
    }
}
