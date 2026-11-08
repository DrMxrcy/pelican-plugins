<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Collection;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;

/**
 * Bug 15: a fresh install (install latest, install version, dependency install) whose file name
 * matches an untracked file already in the folder (a hand-added jar, a "Not on Modrinth" row)
 * must never destroy that file. It is moved aside first and put back on any failure; once the
 * install is recorded the Modrinth file replaces it and the moved-aside copy is removed.
 */
class FreshInstallUntrackedFileTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** The only search hit: Xaero's Minimap. */
    private const XAERO_ROW = '0';

    private const XAERO_JAR = 'xaeros-minimap-x2.jar';

    private const FAPI_JAR = 'P7dR8mSH-fapi2.jar';

    /** @var array<int, array<string, mixed>> the metadata file on the "server" */
    private array $installed = [];

    /** @var array<string, string> what the mods folder holds: file name => content */
    private array $disk = [];

    /** @var array<int, string> files whose download fails */
    private array $failingDownloads = [];

    /** @var array<int, string> projects whose metadata write is refused (bug 14: unreadable metadata) */
    private array $failingMetadataWrites = [];

    /** @var array<int, string> every daemon call, in order: "list", "pull x", "delete x", "rename x > y" */
    private array $calls = [];

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

    private function account(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);
    }

    /** @return array<string, mixed> */
    private static function version(string $projectId, string $versionId, bool $needsFabricApi = false): array
    {
        return [
            'id' => $versionId,
            'project_id' => $projectId,
            'version_number' => $versionId,
            'dependencies' => $needsFabricApi
                ? [['project_id' => 'P7dR8mSH', 'version_id' => null, 'file_name' => null, 'dependency_type' => 'required']]
                : [],
            'files' => [['primary' => true, 'filename' => "$projectId-$versionId.jar", 'url' => "https://cdn.modrinth.com/$projectId-$versionId.jar"]],
        ];
    }

    /** @return array<string, mixed> */
    private static function project(string $id, string $title): array
    {
        return [
            'id' => $id,
            'project_id' => $id,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'title' => $title,
            'description' => "The $title mod",
            'author' => 'someone',
            'downloads' => 10,
            'date_modified' => '2026-01-01T00:00:00Z',
            'icon_url' => null,
            'project_type' => 'mod',
            'server_side' => 'required',
            'client_side' => 'required',
        ];
    }

    /** Xaero's latest build (x2), optionally requiring Fabric API (fapi2). The daemon works on $disk. */
    private function fakeServer(bool $needsFabricApi = false): void
    {
        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturnUsing(function () {
            $this->calls[] = 'list';

            return array_map(
                fn (string $name) => ['name' => $name, 'size' => 1, 'file' => true, 'directory' => false, 'modified' => null],
                array_keys($this->disk),
            );
        });
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(
            fn (array $mods) => array_map(fn (array $mod) => self::project($mod['project_id'], $mod['project_title']), $mods),
        );
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [self::project('xaeros-minimap', "Xaero's Minimap")],
            'total_hits' => 1,
            'versions' => [],
        ]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([self::version('xaeros-minimap', 'x2', $needsFabricApi)]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(
            fn (array $ids) => array_combine($ids, array_map(fn (string $id) => $id === 'P7dR8mSH' ? [self::version('P7dR8mSH', 'fapi2')] : [], $ids)),
        );
        MinecraftModrinth::shouldReceive('getProjectsByIds')->andReturnUsing(
            fn (array $ids) => array_intersect_key(['P7dR8mSH' => self::project('P7dR8mSH', 'Fabric API')], array_flip($ids)),
        );
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnUsing(function (Server $server, string $url, string $folder, string $filename) {
            $this->calls[] = "pull $filename";

            if (in_array($filename, $this->failingDownloads, true)) {
                // A pull that breaks off mid-stream has already truncated the file it writes to.
                $this->disk[$filename] = 'partial';

                throw new Exception("Download of $filename failed");
            }

            // Like Wings: a pull overwrites whatever is there.
            $this->disk[$filename] = 'modrinth';
        });
        MinecraftModrinth::shouldReceive('saveModMetadata')->andReturnUsing(
            function (Server $server, ModrinthProjectType $type, string $id, string $slug, string $title, string $versionId, string $versionNumber, string $filename) {
                if (in_array($id, $this->failingMetadataWrites, true)) {
                    return false;
                }

                $this->installed[] = [
                    'project_id' => $id, 'project_slug' => $slug, 'project_title' => $title,
                    'version_id' => $versionId, 'version_number' => $versionNumber, 'filename' => $filename,
                    'installed_at' => '2026-01-01T00:00:00+00:00',
                ];

                return true;
            },
        );
        MinecraftModrinth::shouldReceive('deleteFile')->andReturnUsing(function (Server $server, string $folder, string $filename) {
            $this->calls[] = "delete $filename";
            unset($this->disk[$filename]);
        });
        // Like Wings: a rename never replaces an existing file.
        MinecraftModrinth::shouldReceive('renameFile')->andReturnUsing(function (Server $server, string $folder, string $from, string $to) {
            $this->calls[] = "rename $from > $to";

            if (!isset($this->disk[$from]) || isset($this->disk[$to])) {
                throw new Exception("Cannot rename $from to $to");
            }

            $this->disk[$to] = $this->disk[$from];
            unset($this->disk[$from]);
        });
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();
    }

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)->loadTable();
    }

    /** @return array<int, string> */
    private function daemonCalls(): array
    {
        return $this->calls;
    }

    private function installLatest(): void
    {
        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));
    }

    /** @return array<int, array<string, mixed>> title + body + status of every notification sent so far */
    private function notifications(): array
    {
        app(NotificationMailbox::class)->flush();

        $component = new Notifications();
        $component->mount();
        $component->pullNotifications();

        /** @var Collection $notifications */
        $notifications = $component->notifications;

        return array_values($notifications->map(fn (Notification $notification) => array_intersect_key($notification->toArray(), array_flip(['title', 'body', 'status'])))->all());
    }

    private function assertInstallFailedNotification(): void
    {
        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('danger', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.notifications.install_failed'), $notifications[0]['title']);
    }

    public function test_install_latest_with_refused_metadata_keeps_the_untracked_file(): void
    {
        $this->account();
        $this->disk = [self::XAERO_JAR => 'hand-added'];
        $this->failingMetadataWrites = ['xaeros-minimap'];
        $this->fakeServer();

        $this->installLatest();

        $this->assertSame([self::XAERO_JAR => 'hand-added'], $this->disk);
        $this->assertInstallFailedNotification();
    }

    public function test_install_latest_with_a_failed_download_keeps_the_untracked_file(): void
    {
        $this->account();
        $this->disk = [self::XAERO_JAR => 'hand-added'];
        $this->failingDownloads = [self::XAERO_JAR];
        $this->fakeServer();

        $this->installLatest();

        $this->assertSame([self::XAERO_JAR => 'hand-added'], $this->disk);
        $this->assertSame([], $this->installed);
        $this->assertInstallFailedNotification();
    }

    public function test_install_version_with_refused_metadata_keeps_the_untracked_file(): void
    {
        $this->account();
        $this->disk = [self::XAERO_JAR => 'hand-added'];
        $this->failingMetadataWrites = ['xaeros-minimap'];
        $this->fakeServer();

        $page = $this->page();
        $version = self::version('xaeros-minimap', 'x2');
        $instance = $page->instance();

        /** @var Action $action */
        $action = (new ReflectionMethod($instance, 'makeInstallVersionAction'))
            ->invoke($instance, 0, self::project('xaeros-minimap', "Xaero's Minimap"), $version, $version['files'][0]);
        $action->call();

        $this->assertSame([self::XAERO_JAR => 'hand-added'], $this->disk);
        $this->assertInstallFailedNotification();
    }

    public function test_a_successful_install_replaces_the_untracked_file_and_leaves_no_copy(): void
    {
        $this->account();
        $this->disk = [self::XAERO_JAR => 'hand-added', 'other.jar' => 'hand-added'];
        $this->fakeServer();

        $this->installLatest();

        $this->assertEquals([self::XAERO_JAR => 'modrinth', 'other.jar' => 'hand-added'], $this->disk);
        $this->assertSame([self::XAERO_JAR], array_column($this->installed, 'filename'));
        $this->assertSame('success', $this->notifications()[0]['status']);
    }

    public function test_a_dependency_install_onto_an_untracked_file_keeps_it_when_its_metadata_is_refused(): void
    {
        $this->account();
        $this->disk = [self::FAPI_JAR => 'hand-added'];
        $this->failingMetadataWrites = ['P7dR8mSH'];
        $this->fakeServer(needsFabricApi: true);

        $this->installLatest();

        $this->assertEquals([self::FAPI_JAR => 'hand-added', self::XAERO_JAR => 'modrinth'], $this->disk);
        $this->assertSame([self::XAERO_JAR], array_column($this->installed, 'filename'));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertSame('warning', $notifications[1]['status']);
        $this->assertStringContainsString('Fabric API', $notifications[1]['body']);
    }

    public function test_a_dependency_install_onto_an_untracked_file_keeps_it_when_its_download_fails(): void
    {
        $this->account();
        $this->disk = [self::FAPI_JAR => 'hand-added'];
        $this->failingDownloads = [self::FAPI_JAR];
        $this->fakeServer(needsFabricApi: true);

        $this->installLatest();

        $this->assertEquals([self::FAPI_JAR => 'hand-added', self::XAERO_JAR => 'modrinth'], $this->disk);
        $this->assertSame([self::XAERO_JAR], array_column($this->installed, 'filename'));
    }

    public function test_install_into_an_empty_slot_moves_and_deletes_nothing(): void
    {
        $this->account();
        $this->disk = ['other.jar' => 'hand-added'];
        $this->fakeServer(needsFabricApi: true);

        $page = $this->page();
        $this->calls = [];

        $page->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $this->assertEquals([self::XAERO_JAR => 'modrinth', self::FAPI_JAR => 'modrinth', 'other.jar' => 'hand-added'], $this->disk);
        // Nothing is moved aside or deleted: the daemon only pulls the two files (plus folder listings).
        $this->assertSame(['pull ' . self::XAERO_JAR, 'pull ' . self::FAPI_JAR], array_values(array_filter($this->daemonCalls(), fn (string $call) => $call !== 'list')));
    }
}
