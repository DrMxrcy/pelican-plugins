<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
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
 * Plan 18: "Pin" / "Unpin" on the Installed tab. A pinned project shows a "Pinned" badge, is
 * offered no update and is skipped by "Update all", which says so. Pinning writes the metadata
 * file, so it needs file.update.
 */
class PinActionsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Installed tab rows, sorted by title. */
    private const ALPHA_ROW = '0';

    private const BETA_ROW = '1';

    /** @var array<int, array<string, mixed>> the metadata file on the "server" */
    private array $installed = [];

    /** @var array<int, string> files pulled by the daemon */
    private array $downloads = [];

    /** @var list<array{string, bool}> setPinned() calls */
    private array $pinCalls = [];

    /** setPinned() answers this (false = the metadata could not be read or written). */
    private bool $pinSucceeds = true;

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
    private static function metadata(string $id, string $title): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $title,
            'version_id' => "$id-old",
            'version_number' => 'old.0',
            'filename' => "$id-old.jar",
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
    private static function project(string $id, string $title): array
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
            'server_side' => 'required',
            'client_side' => 'optional',
        ];
    }

    /**
     * Alpha (Lithium in the plan) and Beta are both outdated; $pinned are pinned.
     *
     * @param  list<string>  $pinned
     */
    private function fakeServer(array $pinned = []): void
    {
        $this->installed = [self::metadata('alpha', 'Alpha'), self::metadata('beta', 'Beta')];

        foreach ($this->installed as $index => $mod) {
            if (in_array($mod['project_id'], $pinned, true)) {
                $this->installed[$index]['pinned'] = true;
            }
        }

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn(['hits' => [self::project('alpha', 'Alpha')], 'total_hits' => 1, 'versions' => []]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturnUsing(fn (array $mods) => array_map(
            fn (array $mod) => self::project($mod['project_id'], $mod['project_title']),
            array_values($mods),
        ));
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturnUsing(fn (string $id) => [self::version($id)]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturnUsing(
            fn (array $ids) => array_combine($ids, array_map(fn (string $id) => [self::version($id)], $ids)),
        );
        MinecraftModrinth::shouldReceive('setPinned')->andReturnUsing(function (Server $server, ModrinthProjectType $type, string $id, bool $pinned) {
            $this->pinCalls[] = [$id, $pinned];

            if (!$this->pinSucceeds) {
                return false;
            }

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
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnUsing(function (Server $server, string $url, string $folder, string $filename) {
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
        MinecraftModrinth::shouldReceive('deleteFile');
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

    private function tableAction(Testable $page, string $name): Action
    {
        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction($name);
        $this->assertNotNull($action, "Action [$name] not found");

        return $action;
    }

    private function assertHandlerForbidden(Action $action): void
    {
        try {
            $action->call();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail("The [{$action->getName()}] handler ran without being allowed to.");
    }

    public function test_pinning_hides_the_update_and_shows_the_badge_and_unpinning_brings_it_back(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page()
            ->assertActionVisible(TestAction::make('update')->table(self::ALPHA_ROW))
            ->assertActionVisible(TestAction::make('pin')->table(self::ALPHA_ROW))
            ->assertActionHidden(TestAction::make('unpin')->table(self::ALPHA_ROW))
            ->assertDontSee(trans('modrinth-plus::strings.pin.badge'))
            ->callAction(TestAction::make('pin')->table(self::ALPHA_ROW));

        $this->assertSame([['alpha', true]], $this->pinCalls);
        $this->assertTrue($this->installed[0]['pinned']);
        $this->assertSame('success', $this->notifications()[0]['status']);

        $this->page()
            ->assertSee(trans('modrinth-plus::strings.pin.badge'))
            ->assertActionHidden(TestAction::make('update')->table(self::ALPHA_ROW))
            ->assertActionHidden(TestAction::make('pin')->table(self::ALPHA_ROW))
            ->assertActionVisible(TestAction::make('unpin')->table(self::ALPHA_ROW))
            // Beta is untouched.
            ->assertActionVisible(TestAction::make('update')->table(self::BETA_ROW))
            ->callAction(TestAction::make('unpin')->table(self::ALPHA_ROW));

        $this->assertSame([['alpha', true], ['alpha', false]], $this->pinCalls);
        $this->assertArrayNotHasKey('pinned', $this->installed[0]);

        $this->page()
            ->assertDontSee(trans('modrinth-plus::strings.pin.badge'))
            ->assertActionVisible(TestAction::make('update')->table(self::ALPHA_ROW))
            ->assertActionVisible(TestAction::make('pin')->table(self::ALPHA_ROW));
    }

    public function test_the_badge_also_shows_on_the_all_tab_where_no_update_is_offered(): void
    {
        $this->account();
        $this->fakeServer(['alpha']);

        $this->page('all')
            ->assertSee(trans('modrinth-plus::strings.pin.badge'))
            ->assertActionHidden(TestAction::make('update')->table('0'))
            ->assertActionVisible(TestAction::make('installed')->table('0'));
    }

    public function test_update_all_skips_the_pinned_project_and_says_so(): void
    {
        $this->account();
        $this->fakeServer(['alpha']);

        $skipped = trans('modrinth-plus::strings.update_all.skipped_pinned', ['list' => 'Alpha']);

        $this->page()
            ->assertActionHasLabel(TestAction::make('update_all')->table(), trans('modrinth-plus::strings.update_all.action', ['count' => 1]))
            ->mountAction(TestAction::make('update_all')->table())
            ->assertMountedActionModalSee('Beta (old.0 → new.0)')
            ->assertMountedActionModalDontSee('Alpha (old.0 → new.0)')
            ->assertMountedActionModalSee($skipped)
            ->callMountedAction();

        // Fails if: the pinned project is downloaded or its metadata rewritten.
        $this->assertSame(['beta-new.jar'], $this->downloads);
        $this->assertSame('alpha-old', $this->installed[0]['version_id']);
        $this->assertTrue($this->installed[0]['pinned']);

        $notifications = $this->notifications();
        $this->assertSame('success', $notifications[0]['status']);
        $this->assertStringContainsString($skipped, $notifications[0]['body']);
    }

    public function test_update_all_is_hidden_when_only_pinned_projects_are_outdated(): void
    {
        $this->account();
        $this->fakeServer(['alpha', 'beta']);

        $this->page()->assertActionHidden(TestAction::make('update_all')->table());
    }

    public function test_a_forged_update_of_a_pinned_project_is_refused(): void
    {
        $this->account();
        $this->fakeServer(['alpha']);

        $this->assertHandlerForbidden($this->tableAction($this->page(), 'update')->record(self::project('alpha', 'Alpha')));
        $this->assertSame([], $this->downloads);
    }

    public function test_installing_a_specific_version_of_a_pinned_project_still_works(): void
    {
        $this->account();
        $this->fakeServer(['alpha']);

        $page = $this->page();
        $instance = $page->instance();
        $version = self::version('alpha');

        /** @var Action $action */
        $action = (new \ReflectionMethod($instance, 'makeInstallVersionAction'))
            ->invoke($instance, 0, self::project('alpha', 'Alpha'), $version, $version['files'][0]);
        $action->call();

        $this->assertSame(['alpha-new.jar'], $this->downloads);
        $this->assertSame('alpha-new', $this->installed[0]['version_id']);
        $this->assertTrue($this->installed[0]['pinned']);
    }

    public function test_a_failed_pin_shows_an_error_and_changes_nothing(): void
    {
        $this->account();
        $this->fakeServer();
        $this->pinSucceeds = false;

        $this->page()->callAction(TestAction::make('pin')->table(self::ALPHA_ROW));

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame('danger', $notifications[0]['status']);
        $this->assertSame(trans('modrinth-plus::strings.pin.failed'), $notifications[0]['title']);
        $this->assertArrayNotHasKey('pinned', $this->installed[0]);

        $this->page()
            ->assertActionVisible(TestAction::make('update')->table(self::ALPHA_ROW))
            ->assertActionVisible(TestAction::make('pin')->table(self::ALPHA_ROW));
    }

    public function test_subuser_without_file_update_cannot_see_or_trigger_pin_or_unpin(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileCreate, SubuserPermission::FileDelete]);
        $this->fakeServer(['beta']);

        $page = $this->page()
            ->assertActionHidden(TestAction::make('pin')->table(self::ALPHA_ROW))
            ->assertActionHidden(TestAction::make('unpin')->table(self::BETA_ROW))
            // The badge still tells them why Beta has no update.
            ->assertSee(trans('modrinth-plus::strings.pin.badge'));

        $this->assertHandlerForbidden($this->tableAction($page, 'pin')->record(self::project('alpha', 'Alpha')));
        $this->assertHandlerForbidden($this->tableAction($page, 'unpin')->record(self::project('beta', 'Beta')));

        $this->assertSame([], $this->pinCalls);
    }

    public function test_subuser_with_file_update_can_pin(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileUpdate]);
        $this->fakeServer();

        $this->page()
            ->assertActionVisible(TestAction::make('pin')->table(self::ALPHA_ROW))
            ->callAction(TestAction::make('pin')->table(self::ALPHA_ROW));

        $this->assertSame([['alpha', true]], $this->pinCalls);
    }

    public function test_pin_is_offered_on_the_installed_tab_only(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page('all')->assertActionHidden(TestAction::make('pin')->table('0'));
    }
}
