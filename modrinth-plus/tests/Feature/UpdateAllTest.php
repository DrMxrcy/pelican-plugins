<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
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
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 10: "Update all" on the Installed tab updates every installed project with a newer
 * compatible version through the single-update path. Client-only projects and projects no
 * longer on Modrinth are skipped, and one failing project never stops the others.
 */
class UpdateAllTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> the metadata file on the "server" */
    private array $installed = [];

    /** @var array<int, string> files pulled by the daemon */
    private array $downloads = [];

    /** @var array<int, string> files deleted by the daemon */
    private array $deleted = [];

    /** @var array<int, string> downloads that fail */
    private array $failingDownloads = [];

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

    /** @return array<string, mixed> */
    private static function metadata(string $id, string $title, string $versionId = 'old'): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $title,
            'version_id' => "$id-$versionId",
            'version_number' => "$versionId.0",
            'filename' => "$id-$versionId.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private static function version(string $id): array
    {
        return [
            'id' => "$id-new",
            'project_id' => $id,
            'version_number' => 'new.0',
            'dependencies' => [],
            'files' => [['primary' => true, 'filename' => "$id-new.jar", 'url' => "https://cdn.modrinth.com/$id-new.jar"]],
        ];
    }

    /** @return array<string, mixed> */
    private static function project(string $id, string $title, string $serverSide = 'required'): array
    {
        return [
            'id' => $id,
            'project_id' => $id,
            'slug' => $id,
            'title' => $title,
            'description' => "The $title mod",
            'author' => 'someone',
            'downloads' => 10,
            'date_modified' => '2026-01-01T00:00:00Z',
            'icon_url' => null,
            'project_type' => 'mod',
            'server_side' => $serverSide,
            'client_side' => 'optional',
        ];
    }

    /**
     * Alpha and Beta are outdated, Sodium is outdated but client-only, Current is up to date
     * and Gone is no longer on Modrinth. Writes go to $installed, $downloads and $deleted.
     */
    private function fakeServer(bool $everythingCurrent = false): void
    {
        $this->installed = [
            self::metadata('alpha', 'Alpha'),
            self::metadata('beta', 'Beta'),
            self::metadata('sodium', 'Sodium'),
            self::metadata('current', 'Current', 'new'),
            self::metadata('gone', 'Gone'),
        ];

        if ($everythingCurrent) {
            $this->installed = [self::metadata('current', 'Current', 'new')];
        }

        $versionsOf = fn (string $id) => $id === 'gone' ? [] : [self::version($id)];

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn(['hits' => [], 'total_hits' => 0, 'versions' => []]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(fn (array $mods) => array_map(
            fn (array $mod) => match ($mod['project_id']) {
                'gone' => [
                    'project_id' => 'gone', 'slug' => 'gone', 'title' => 'Gone',
                    'description' => 'This project is no longer available on Modrinth.', 'icon_url' => null,
                    'author' => '', 'downloads' => 0, 'date_modified' => '2026-01-01T00:00:00+00:00',
                    'project_type' => '', 'unavailable' => true,
                ],
                'sodium' => self::project('sodium', 'Sodium', 'unsupported'),
                default => self::project($mod['project_id'], $mod['project_title']),
            },
            array_values($mods),
        ));
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturnUsing(fn (string $id) => $versionsOf($id));
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(
            fn (array $ids) => array_combine($ids, array_map($versionsOf, $ids)),
        );
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnUsing(function (Server $server, string $url, string $folder, string $filename) {
            if (in_array($filename, $this->failingDownloads, true)) {
                throw new Exception("Download of $filename failed");
            }

            $this->downloads[] = $filename;
        });
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
        MinecraftModrinth::shouldReceive('deleteFile')->andReturnUsing(function (Server $server, string $folder, string $filename) {
            $this->deleted[] = $filename;
        });
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();
    }

    private function page(string $tab = 'installed'): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', $tab)
            ->loadTable();
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

    public function test_updates_every_outdated_project_and_removes_the_old_jars(): void
    {
        $this->account();
        $this->fakeServer();

        $page = $this->page()
            ->assertActionVisible(TestAction::make('update_all')->table())
            ->assertActionHasLabel(TestAction::make('update_all')->table(), trans('modrinth-plus::strings.update_all.action', ['count' => 2]))
            ->mountAction(TestAction::make('update_all')->table())
            ->assertMountedActionModalSee(['Alpha (old.0 → new.0)', 'Beta (old.0 → new.0)'])
            ->assertMountedActionModalDontSee(['Sodium', 'Gone', 'Current']);

        // Nothing is touched before the confirmation.
        $this->assertSame([], $this->downloads);

        $page->callMountedAction();

        // Fails if: a client-only, unavailable or current project is touched.
        $this->assertSame(['alpha-new.jar', 'beta-new.jar'], $this->downloads);
        $this->assertSame(['alpha-old.jar', 'beta-old.jar'], $this->deleted);

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.update_all.success_title'), $notifications[0]['title']);
        $this->assertStringContainsString('Alpha, Beta', $notifications[0]['body']);

        // Afterwards no row offers an update and the button is gone.
        $this->page()
            ->assertActionHidden(TestAction::make('update_all')->table())
            ->assertActionHidden(TestAction::make('update')->table('0'))
            ->assertActionHidden(TestAction::make('update')->table('1'));
    }

    public function test_a_failing_download_does_not_stop_the_others(): void
    {
        $this->account();
        $this->fakeServer();
        $this->failingDownloads = ['alpha-new.jar'];

        $this->page()->callAction(TestAction::make('update_all')->table());

        $this->assertSame(['beta-new.jar'], $this->downloads);
        // Fails if: Alpha's old jar or metadata is touched after its download failed.
        $this->assertSame(['beta-old.jar'], $this->deleted);
        $this->assertSame('alpha-old', $this->installed[0]['version_id']);
        $this->assertSame('beta-new', $this->installed[1]['version_id']);

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('warning', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.update_all.partial_title'), $notifications[0]['title']);
        $this->assertStringContainsString(trans('modrinth-plus::strings.update_all.updated', ['list' => 'Beta']), $notifications[0]['body']);
        $this->assertStringContainsString(trans('modrinth-plus::strings.update_all.failed', ['list' => 'Alpha']), $notifications[0]['body']);
    }

    public function test_every_download_failing_is_reported_as_failed(): void
    {
        $this->account();
        $this->fakeServer();
        $this->failingDownloads = ['alpha-new.jar', 'beta-new.jar'];

        $this->page()->callAction(TestAction::make('update_all')->table());

        $this->assertSame([], $this->deleted);

        $notifications = $this->notifications();
        $this->assertSame('danger', $notifications[0]['status']);
        $this->assertStringContainsString('Alpha, Beta', $notifications[0]['body']);
    }

    public function test_hidden_when_nothing_can_be_updated(): void
    {
        $this->account();
        $this->fakeServer(everythingCurrent: true);

        $this->page()->assertActionHidden(TestAction::make('update_all')->table());
    }

    public function test_hidden_on_the_all_tab(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page('all')->assertActionHidden(TestAction::make('update_all')->table());
    }

    public function test_subuser_without_file_delete_cannot_run_it(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileCreate]);
        $this->fakeServer();

        $page = $this->page()->assertActionHidden(TestAction::make('update_all')->table());

        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction('update_all');
        $this->assertInstanceOf(Action::class, $action);

        // Runs the handler directly, past the hidden button, as a forged request would.
        try {
            $action->call();
            $this->fail('The update_all handler ran without file.delete.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame([], $this->downloads);
        $this->assertSame([], $this->deleted);
    }
}
