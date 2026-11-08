<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 13: the Mods/Plugins page and its jar-writing actions must honour the
 * subuser file permissions (file.read to see the page, file.create to install,
 * file.create + file.delete to update, file.delete to uninstall).
 */
class SubuserFilePermissionsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Table row keys: the first hit is installed (with an update available), the second is not. */
    private const INSTALLED_ROW = '0';

    private const NOT_INSTALLED_ROW = '1';

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
     * @param  array<int, SubuserPermission>  $permissions  empty = the server owner
     * @return array{User, Server}
     */
    private function account(array $permissions = []): array
    {
        [$user, $server] = $this->generateTestAccount($permissions);

        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        return [$user, $server];
    }

    /**
     * Fakes every Modrinth/daemon call the page makes while rendering. Unless $forbidWrites
     * is false, writing or deleting a jar (or its metadata) fails the test.
     */
    private function fakeModrinth(bool $forbidWrites = true): void
    {
        $version = [
            'id' => 'v2',
            'version_number' => '2.0.0',
            'files' => [['primary' => true, 'filename' => 'alpha-2.0.0.jar', 'url' => 'https://cdn.modrinth.com/alpha-2.0.0.jar']],
        ];

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
        MinecraftModrinth::shouldReceive('getProjects')->andReturn([
            'hits' => [$this->hit('alpha'), $this->hit('beta')],
            'total_hits' => 2,
        ]);
        MinecraftModrinth::shouldReceive('getProjectVersions')->andReturn([$version]);

        if (!$forbidWrites) {
            return;
        }

        MinecraftModrinth::shouldReceive('downloadFile')->never();
        MinecraftModrinth::shouldReceive('deleteFile')->never();
        MinecraftModrinth::shouldReceive('saveModMetadata')->never();
        MinecraftModrinth::shouldReceive('removeModMetadata')->never();
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

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)->loadTable();
    }

    /**
     * Runs an action's handler directly, skipping Filament's visibility/authorization gate,
     * to prove the handler itself refuses (a hidden button alone is not a permission check).
     *
     * @param  array<string, mixed>  $record
     */
    private function assertHandlerForbidden(Testable $page, string $name, array $record): void
    {
        /** @var MinecraftModrinthModPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction($name);
        $this->assertNotNull($action, "Action [$name] not found");

        try {
            $action->record($record)->call();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail("The [$name] handler ran without the required file permission.");
    }

    public function test_subuser_without_file_read_cannot_open_the_page(): void
    {
        $this->account([SubuserPermission::ControlConsole]);
        $this->fakeModrinth();

        $this->assertFalse(MinecraftModrinthModPage::canAccess());

        Livewire::test(MinecraftModrinthModPage::class)->assertForbidden();
    }

    public function test_subuser_with_file_read_only_cannot_install_update_or_uninstall(): void
    {
        $this->account([SubuserPermission::FileRead]);
        $this->fakeModrinth();

        $this->assertTrue(MinecraftModrinthModPage::canAccess());

        $page = $this->page()
            ->assertActionHidden(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('uninstall')->table(self::INSTALLED_ROW));

        $this->assertHandlerForbidden($page, 'install_latest', $this->hit('beta'));
        $this->assertHandlerForbidden($page, 'update', $this->hit('alpha'));
        $this->assertHandlerForbidden($page, 'uninstall', $this->hit('alpha'));
    }

    public function test_subuser_without_file_delete_cannot_update_or_uninstall(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileCreate]);
        $this->fakeModrinth();

        $page = $this->page()
            ->assertActionVisible(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('uninstall')->table(self::INSTALLED_ROW));

        $this->assertHandlerForbidden($page, 'update', $this->hit('alpha'));
        $this->assertHandlerForbidden($page, 'uninstall', $this->hit('alpha'));
    }

    public function test_subuser_without_file_create_cannot_install_or_update_but_can_uninstall(): void
    {
        $this->account([SubuserPermission::FileRead, SubuserPermission::FileDelete]);
        $this->fakeModrinth();

        $page = $this->page()
            ->assertActionHidden(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionHidden(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::INSTALLED_ROW));

        $this->assertHandlerForbidden($page, 'install_latest', $this->hit('beta'));
        $this->assertHandlerForbidden($page, 'update', $this->hit('alpha'));
    }

    public function test_owner_keeps_every_action(): void
    {
        $this->account();
        $this->fakeModrinth();

        $this->assertTrue(MinecraftModrinthModPage::canAccess());

        $this->page()
            ->assertActionVisible(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::INSTALLED_ROW));
    }

    public function test_root_admin_keeps_every_action(): void
    {
        [, $server] = $this->account();

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        Filament::setTenant($server);

        $this->fakeModrinth();

        $this->assertTrue(MinecraftModrinthModPage::canAccess());

        $this->page()
            ->assertActionVisible(TestAction::make('install_latest')->table(self::NOT_INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('update')->table(self::INSTALLED_ROW))
            ->assertActionVisible(TestAction::make('uninstall')->table(self::INSTALLED_ROW));
    }

    public function test_owner_can_uninstall(): void
    {
        $this->account();
        $this->fakeModrinth(forbidWrites: false);

        MinecraftModrinth::shouldReceive('deleteFile')->once()->with(Mockery::type(Server::class), 'mods', 'alpha-1.0.0.jar');
        MinecraftModrinth::shouldReceive('removeModMetadata')->once()->andReturn(true);

        $this->page()->callAction(TestAction::make('uninstall')->table(self::INSTALLED_ROW));
    }
}
