<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Tests\Traits\Integration\CreatesTestModels;
use ArrayObject;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Collection;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 7: installing or updating a version also installs its missing required dependencies
 * (never optional ones), records them in the metadata and names them in the notification. A
 * dependency that fails is reported and never takes the main mod with it.
 */
class RequiredDependenciesTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** The only search hit: Xaero's Minimap. */
    private const XAERO_ROW = '0';

    /** @var array<int, array<string, mixed>> */
    private array $installed = [];

    /** The metadata file can't be read (daemon unreachable). */
    private bool $metadataDown = false;

    /** @var array<int, string> project ids whose versions were looked up as a dependency */
    private array $dependencyLookups = [];

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
    }

    /**
     * @param  array<int, array<string, mixed>>  $dependencies
     * @return array<string, mixed>
     */
    private static function version(string $projectId, string $versionId, array $dependencies = []): array
    {
        return [
            'id' => $versionId,
            'project_id' => $projectId,
            'version_number' => $versionId,
            'dependencies' => $dependencies,
            'files' => [['primary' => true, 'filename' => "$projectId-$versionId.jar", 'url' => "https://cdn.modrinth.com/$projectId-$versionId.jar"]],
        ];
    }

    /** @return array<string, mixed> */
    private static function dep(string $projectId, string $type = 'required'): array
    {
        return ['project_id' => $projectId, 'version_id' => null, 'file_name' => null, 'dependency_type' => $type];
    }

    /** @return array<string, mixed> */
    private static function project(string $id, string $title, string $serverSide = 'required'): array
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
            'server_side' => $serverSide,
            'client_side' => 'required',
        ];
    }

    /** @return array<string, mixed> */
    private static function metadata(string $id, string $title): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $title,
            'version_id' => "$id-old",
            'version_number' => '0.1',
            'filename' => "$id-old.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /**
     * Fakes Modrinth: Xaero's latest build requires Fabric API (P7dR8mSH) and optionally Mod Menu.
     *
     * @param  array<string, array<int, array<string, mixed>>|null>  $catalogue  compatible versions per dependency
     * @param  array<string, mixed>|null  $xaeroVersion
     * @param  bool  $projectsDown  `/v2/projects` fails, so no dependency's sides are known
     */
    private function fakeModrinth(array $catalogue = [], ?array $xaeroVersion = null, bool $projectsDown = false): void
    {
        $xaeroVersion ??= self::version('xaeros-minimap', 'x2', [self::dep('P7dR8mSH'), self::dep('modmenu', 'optional')]);
        $catalogue += [
            'P7dR8mSH' => [self::version('P7dR8mSH', 'fapi2'), self::version('P7dR8mSH', 'fapi1')],
            'modmenu' => [self::version('modmenu', 'mm1')],
        ];

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->metadataDown ? null : $this->installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(
            fn (array $mods) => array_map(fn (array $mod) => self::project($mod['project_id'], $mod['project_title']), $mods),
        );
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [self::project('xaeros-minimap', "Xaero's Minimap")],
            'total_hits' => 1,
            'versions' => [],
        ]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([$xaeroVersion]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(function (array $ids) use ($catalogue) {
            array_push($this->dependencyLookups, ...$ids);

            return array_combine($ids, array_map(fn (string $id) => array_key_exists($id, $catalogue) ? $catalogue[$id] : [], $ids));
        });
        MinecraftModrinth::shouldReceive('getProjectsByIds')->andReturnUsing(fn (array $ids) => $projectsDown ? [] : array_intersect_key([
            'P7dR8mSH' => self::project('P7dR8mSH', 'Fabric API'),
            'modmenu' => self::project('modmenu', 'Mod Menu'),
            'cloth' => self::project('cloth', 'Cloth Config'),
            'sodium' => self::project('sodium', 'Sodium', 'unsupported'),
        ], array_flip($ids)));
    }

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)->loadTable();
    }

    /** @return array<int, array<string, mixed>> title + body of every notification sent so far */
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

    private function expectDownloads(string ...$filenames): void
    {
        foreach ($filenames as $filename) {
            MinecraftModrinth::shouldReceive('downloadFile')->once()->with(Mockery::type(Server::class), Mockery::any(), 'mods', $filename);
        }
    }

    public function test_install_latest_also_installs_a_missing_required_dependency(): void
    {
        $this->account();
        $this->fakeModrinth();

        $this->expectDownloads('xaeros-minimap-x2.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'xaeros-minimap', 'xaero\'s-minimap', "Xaero's Minimap", 'x2', 'x2', 'xaeros-minimap-x2.jar', 'someone')
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', 'fapi2', 'P7dR8mSH-fapi2.jar', null, 'xaeros-minimap')
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->never();

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringContainsString('Fabric API', $notifications[0]['body']);
        $this->assertStringNotContainsString('Mod Menu', $notifications[0]['body']);
    }

    public function test_install_version_from_the_versions_modal_installs_dependencies_too(): void
    {
        $this->account();
        $this->fakeModrinth();

        $this->expectDownloads('xaeros-minimap-x2.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->twice()->andReturn(true);

        $page = $this->page();
        $version = self::version('xaeros-minimap', 'x2', [self::dep('P7dR8mSH')]);
        $instance = $page->instance();

        /** @var Action $action */
        $action = (new ReflectionMethod($instance, 'makeInstallVersionAction'))
            ->invoke($instance, 0, self::project('xaeros-minimap', "Xaero's Minimap"), $version, $version['files'][0]);
        $action->call();

        $this->assertStringContainsString('Fabric API', $this->notifications()[0]['body']);
    }

    public function test_dependencies_already_installed_download_nothing_extra(): void
    {
        $this->account();
        $this->installed = [self::metadata('P7dR8mSH', 'Fabric API')];
        $this->fakeModrinth();

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertStringNotContainsString('Fabric API', $notifications[0]['body']);
        $this->assertSame([], $this->dependencyLookups);
    }

    public function test_optional_dependencies_are_never_installed(): void
    {
        $this->account();
        $this->fakeModrinth(xaeroVersion: self::version('xaeros-minimap', 'x2', [self::dep('modmenu', 'optional'), self::dep('cloth', 'embedded')]));

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $this->assertCount(1, $this->notifications());
    }

    public function test_a_failed_dependency_keeps_the_main_mod_and_is_reported(): void
    {
        $this->account();
        $this->fakeModrinth(['cloth' => [self::version('cloth', 'c1')]], self::version('xaeros-minimap', 'x2', [self::dep('P7dR8mSH'), self::dep('cloth')]));

        $this->expectDownloads('xaeros-minimap-x2.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('downloadFile')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'mods', 'cloth-c1.jar')
            ->andThrow(new Exception('daemon said no'));
        MinecraftModrinth::shouldReceive('saveModMetadata')->twice()->andReturn(true);
        // Nothing is rolled back: not the main jar, not the dependency that did install.
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringContainsString('Fabric API', $notifications[0]['body']);
        $this->assertSame('warning', $notifications[1]['status']);
        $this->assertStringContainsString('Cloth Config', $notifications[1]['body']);
    }

    public function test_unavailable_and_client_only_dependencies_are_reported_not_installed(): void
    {
        $this->account();
        $this->fakeModrinth(['nobuild' => [], 'sodium' => [self::version('sodium', 's1')]], self::version('xaeros-minimap', 'x2', [
            self::dep('nobuild'),
            self::dep('sodium'),
            ['project_id' => null, 'version_id' => null, 'file_name' => 'custom-lib.jar', 'dependency_type' => 'required'],
        ]));

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringContainsString('nobuild', $notifications[1]['body']);
        $this->assertStringContainsString('Sodium', $notifications[1]['body']);
        $this->assertStringContainsString('custom-lib.jar', $notifications[1]['body']);
    }

    public function test_a_shared_dependency_is_installed_when_one_of_its_parents_is_not(): void
    {
        // Diamond: Xaero needs Sodium (client-only, skipped) and Cloth Config; both need Fabric API.
        // Fabric API is still installed for Cloth Config and recorded as its dependency.
        $this->account();
        $this->fakeModrinth([
            'sodium' => [self::version('sodium', 's1', [self::dep('P7dR8mSH')])],
            'cloth' => [self::version('cloth', 'c1', [self::dep('P7dR8mSH')])],
        ], self::version('xaeros-minimap', 'x2', [self::dep('sodium'), self::dep('cloth')]));

        $this->expectDownloads('xaeros-minimap-x2.jar', 'cloth-c1.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'xaeros-minimap', Mockery::any(), Mockery::any(), 'x2', 'x2', 'xaeros-minimap-x2.jar', Mockery::any())
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'cloth', 'cloth-config', 'Cloth Config', 'c1', 'c1', 'cloth-c1.jar', null, 'xaeros-minimap')
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', 'fapi2', 'P7dR8mSH-fapi2.jar', null, 'cloth')
            ->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertStringContainsString('Cloth Config', $notifications[0]['body']);
        $this->assertStringContainsString('Fabric API', $notifications[0]['body']);
        $this->assertStringContainsString('Sodium', $notifications[1]['body']);
    }

    public function test_a_shared_dependency_is_skipped_when_every_parent_is_not_installed(): void
    {
        $this->account();
        $this->fakeModrinth([
            'sodium' => [self::version('sodium', 's1', [self::dep('P7dR8mSH')])],
            'cloth' => [self::version('cloth', 'c1', [self::dep('P7dR8mSH')])],
        ], self::version('xaeros-minimap', 'x2', [self::dep('sodium'), self::dep('cloth')]));

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('downloadFile')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'mods', 'cloth-c1.jar')
            ->andThrow(new Exception('daemon said no'));
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertStringNotContainsString('Fabric API', $notifications[0]['body']);
        $this->assertStringNotContainsString('Fabric API', $notifications[1]['body']);
    }

    public function test_dependencies_whose_sides_cannot_be_checked_are_not_installed(): void
    {
        // Modrinth's /v2/projects is down: Sodium could be client-only, so it is reported, not
        // installed, and neither is what it pulls in.
        $this->account();
        $this->fakeModrinth(['sodium' => [self::version('sodium', 's1', [self::dep('P7dR8mSH')])]], self::version('xaeros-minimap', 'x2', [self::dep('sodium')]), projectsDown: true);

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertSame('warning', $notifications[1]['status']);
        $this->assertStringContainsString('could not be checked', $notifications[1]['body']);
        $this->assertStringContainsString('sodium', $notifications[1]['body']);
        $this->assertStringNotContainsString('P7dR8mSH', $notifications[1]['body']);
    }

    public function test_dependencies_are_not_installed_when_the_metadata_cannot_be_read(): void
    {
        // Without the metadata every dependency would look missing and be downloaded again.
        $this->account();
        $this->fakeModrinth();
        $this->metadataDown = true;

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringNotContainsString('Fabric API', $notifications[0]['body']);
        $this->assertSame('warning', $notifications[1]['status']);
        $this->assertStringContainsString('could not be checked', $notifications[1]['body']);
        $this->assertSame([], $this->dependencyLookups);
    }

    public function test_update_installs_dependencies_the_new_version_added(): void
    {
        $this->account();
        $this->installed = [self::metadata('xaeros-minimap', "Xaero's Minimap")];
        $this->fakeModrinth();

        $this->expectDownloads('xaeros-minimap-x2.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'xaeros-minimap', Mockery::any(), Mockery::any(), 'x2', 'x2', 'xaeros-minimap-x2.jar', Mockery::any())
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', 'fapi2', 'P7dR8mSH-fapi2.jar', null, 'xaeros-minimap')
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'xaeros-minimap-old.jar');

        $this->page()->callAction(TestAction::make('update')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringContainsString('Fabric API', $notifications[0]['body']);
    }

    public function test_an_installed_incompatible_project_asks_for_confirmation_but_does_not_block(): void
    {
        $this->account();
        $this->installed = [self::metadata('optifabric', 'OptiFabric')];
        $this->fakeModrinth(xaeroVersion: self::version('xaeros-minimap', 'x2', [self::dep('optifabric', 'incompatible')]));

        $downloads = [];
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnUsing(function (Server $server, string $url, string $folder, string $filename) use (&$downloads) {
            $downloads[] = $filename;
        });
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        $page = $this->page()
            ->mountAction(TestAction::make('install_latest')->table(self::XAERO_ROW))
            ->assertActionMounted(TestAction::make('install_latest')->table(self::XAERO_ROW))
            ->assertMountedActionModalSee('OptiFabric');

        $this->assertSame([], $downloads);

        $page->callMountedAction();

        $this->assertSame(['xaeros-minimap-x2.jar'], $downloads);
        $this->assertSame('success', $this->notifications()[0]['status']);
    }

    public function test_no_confirmation_without_an_installed_incompatible_project(): void
    {
        $this->account();
        $this->installed = [self::metadata('P7dR8mSH', 'Fabric API')];
        $this->fakeModrinth(xaeroVersion: self::version('xaeros-minimap', 'x2', [self::dep('optifabric', 'incompatible'), self::dep('P7dR8mSH')]));

        $this->expectDownloads('xaeros-minimap-x2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()->andReturn(true);

        // Without a modal, mounting runs the action straight away.
        $this->page()
            ->mountAction(TestAction::make('install_latest')->table(self::XAERO_ROW))
            ->assertActionNotMounted(TestAction::make('install_latest')->table(self::XAERO_ROW));
    }

    public function test_update_modal_mentions_an_installed_incompatible_project(): void
    {
        $this->account();
        $this->installed = [self::metadata('xaeros-minimap', "Xaero's Minimap"), self::metadata('optifabric', 'OptiFabric')];
        $this->fakeModrinth(xaeroVersion: self::version('xaeros-minimap', 'x2', [self::dep('optifabric', 'incompatible')]));

        MinecraftModrinth::shouldReceive('downloadFile')->never();

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::XAERO_ROW))
            ->assertMountedActionModalSee('OptiFabric');
    }

    public function test_main_install_failure_installs_no_dependencies(): void
    {
        $this->account();
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('downloadFile')->once()->andThrow(new Exception('daemon down'));
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('danger', $notifications[0]['status']);
        $this->assertSame([], $this->dependencyLookups);
    }

    public function test_subuser_without_file_create_cannot_trigger_dependency_installs(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileDelete]);
        $this->fakeModrinth();

        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();

        $page = $this->page()->assertActionHidden(TestAction::make('install_latest')->table(self::XAERO_ROW));

        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction('install_latest');
        $this->assertNotNull($action);

        try {
            $action->record(self::project('xaeros-minimap', "Xaero's Minimap"))->call();
            $this->fail('install_latest ran without file.create');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame([], $this->dependencyLookups);
    }

    /**
     * Routes saveModMetadata()/removeModMetadata() to the real service on a daemon whose metadata
     * read fails (plan 14), so the page sees what the service really returns.
     *
     * @return ArrayObject<int, string> metadata writes the daemon received
     */
    private function realMetadataWritesWithFailingRead(): ArrayObject
    {
        /** @var ArrayObject<int, string> $writes */
        $writes = new ArrayObject();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getContent')->andThrow(new Exception('cURL error 28: connection timed out'));
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) use ($writes) {
            $writes[] = $content;

            return new Response(new Psr7Response(204));
        });
        $this->app->instance(DaemonFileRepository::class, $repository);

        $service = new MinecraftModrinthService();
        MinecraftModrinth::shouldReceive('saveModMetadata')->andReturnUsing(fn (...$arguments) => $service->saveModMetadata(...$arguments));
        MinecraftModrinth::shouldReceive('removeModMetadata')->andReturnUsing(fn (...$arguments) => $service->removeModMetadata(...$arguments));

        return $writes;
    }

    public function test_install_reports_a_failure_when_the_metadata_cannot_be_read(): void
    {
        $this->account();
        $this->fakeModrinth();
        $writes = $this->realMetadataWritesWithFailingRead();

        $this->expectDownloads('xaeros-minimap-x2.jar');
        // The new jar is rolled back, since nothing records it.
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'xaeros-minimap-x2.jar');

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $this->assertSame([], $writes->getArrayCopy());
        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('danger', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.notifications.install_failed'), $notifications[0]['title']);
        $this->assertSame([], $this->dependencyLookups);
    }

    public function test_update_reports_a_failure_when_the_metadata_cannot_be_read(): void
    {
        $this->account();
        $this->installed = [self::metadata('xaeros-minimap', "Xaero's Minimap")];
        $this->fakeModrinth();
        $writes = $this->realMetadataWritesWithFailingRead();

        $this->expectDownloads('xaeros-minimap-x2.jar');
        // Only the new jar is removed again; the installed one stays.
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'xaeros-minimap-x2.jar');

        $this->page()->callAction(TestAction::make('update')->table(self::XAERO_ROW));

        $this->assertSame([], $writes->getArrayCopy());
        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('danger', $notifications[0]['status']);
    }

    public function test_uninstall_does_not_report_success_when_the_metadata_cannot_be_read(): void
    {
        $this->account();
        $this->installed = [self::metadata('xaeros-minimap', "Xaero's Minimap")];
        $this->fakeModrinth();
        $writes = $this->realMetadataWritesWithFailingRead();

        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'xaeros-minimap-old.jar');

        $this->page()->callAction(TestAction::make('uninstall')->table(self::XAERO_ROW));

        $this->assertSame([], $writes->getArrayCopy());
        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('warning', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.notifications.uninstall_partial'), $notifications[0]['title']);
    }

    public function test_a_dependency_whose_metadata_cannot_be_saved_is_rolled_back_and_reported(): void
    {
        $this->account();
        $this->fakeModrinth();

        $this->expectDownloads('xaeros-minimap-x2.jar', 'P7dR8mSH-fapi2.jar');
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'xaeros-minimap', Mockery::any(), Mockery::any(), 'x2', 'x2', 'xaeros-minimap-x2.jar', Mockery::any())
            ->andReturn(true);
        MinecraftModrinth::shouldReceive('saveModMetadata')->once()
            ->with(Mockery::type(Server::class), Mockery::any(), 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', 'fapi2', 'P7dR8mSH-fapi2.jar', null, 'xaeros-minimap')
            ->andReturn(false);
        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'P7dR8mSH-fapi2.jar');

        $this->page()->callAction(TestAction::make('install_latest')->table(self::XAERO_ROW));

        $notifications = $this->notifications();
        $this->assertCount(2, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringNotContainsString('Fabric API', $notifications[0]['body']);
        $this->assertSame('warning', $notifications[1]['status']);
        $this->assertStringContainsString('Fabric API', $notifications[1]['body']);
    }
}
