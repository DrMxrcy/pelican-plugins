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

/**
 * Plan 33: mods Modpacks Plus recorded (`installed_as_modpack` on the metadata entry) show a
 * "From modpack <name>" badge, and updating one, alone or through "Update all", warns that it
 * may break the pack first. The update itself still works.
 */
class ModpackBadgeTest extends TestCase
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

    /** Has markup characters: the badge and dialogs must escape it. */
    private const PACK = 'Cool <Pack>';

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
     * Alpha and Beta are both outdated; Alpha came with the modpack "Cool <Pack>".
     */
    private function fakeServer(): void
    {
        $this->installed = [self::metadata('alpha', 'Alpha'), self::metadata('beta', 'Beta')];
        $this->installed[0]['installed_as_modpack'] = ['provider' => 'modrinth', 'pack_id' => 'PACKID01', 'version_id' => 'VERSID01', 'pack_name' => self::PACK, 'version_number' => '1.0.0'];

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

    private function badge(): string
    {
        return e(trans('modrinth-plus::strings.modpack.badge', ['name' => self::PACK]));
    }

    public function test_a_pack_mod_shows_the_badge_and_other_mods_do_not(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page()
            ->assertSeeHtml($this->badge())
            ->assertDontSeeHtml('Cool <Pack>');

        $this->installed[0] = self::metadata('alpha', 'Alpha');
        $this->page()->assertDontSee(trans('modrinth-plus::strings.modpack.badge', ['name' => '']));
    }

    public function test_the_badge_also_shows_on_the_all_tab(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page('all')->assertSeeHtml($this->badge());
    }

    public function test_updating_a_pack_mod_warns_first_then_updates_and_keeps_the_tag(): void
    {
        $this->account();
        $this->fakeServer();

        $warning = trans('modrinth-plus::strings.modpack.update_warning', ['name' => self::PACK]);

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::ALPHA_ROW))
            ->assertMountedActionModalSee($warning)
            ->callMountedAction();

        $this->assertSame(['alpha-new.jar'], $this->downloads);
        $this->assertSame('alpha-new', $this->installed[0]['version_id']);
        $this->assertSame(self::PACK, $this->installed[0]['installed_as_modpack']['pack_name']);
        $this->assertSame('success', $this->notifications()[0]['status']);
    }

    public function test_updating_another_mod_has_no_pack_warning(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::BETA_ROW))
            ->assertMountedActionModalDontSee(trans('modrinth-plus::strings.modpack.update_warning', ['name' => self::PACK]));
    }

    public function test_update_all_names_the_pack_mods_it_will_update(): void
    {
        $this->account();
        $this->fakeServer();

        $this->page()
            ->mountAction(TestAction::make('update_all')->table())
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.modpack.update_all_warning', ['list' => 'Alpha ('.self::PACK.')']))
            ->callMountedAction();

        $this->assertEqualsCanonicalizing(['alpha-new.jar', 'beta-new.jar'], $this->downloads);
    }

    /**
     * The install button of one version in the versions modal, as the page builds it.
     *
     * @param  array<string, mixed>  $version
     */
    private function installVersionAction(string $id, string $title, array $version): Action
    {
        $page = $this->page();
        $instance = $page->instance();

        /** @var Action $action */
        $action = (new \ReflectionMethod($instance, 'makeInstallVersionAction'))
            ->invoke($instance, 0, self::project($id, $title), $version, $version['files'][0]);

        return $action;
    }

    public function test_installing_another_version_of_a_pack_mod_from_the_versions_modal_warns_first(): void
    {
        $this->account();
        $this->fakeServer();

        $warning = trans('modrinth-plus::strings.modpack.update_warning', ['name' => self::PACK]);
        $action = $this->installVersionAction('alpha', 'Alpha', self::version('alpha'));

        // Fails if: the jar of a pack mod is replaced without the pack warning.
        $this->assertTrue($action->isConfirmationRequired());
        $this->assertStringContainsString($warning, (string) $action->getModalDescription());

        $action->call();
        $this->assertSame(['alpha-new.jar'], $this->downloads);
        $this->assertSame(self::PACK, $this->installed[0]['installed_as_modpack']['pack_name']);
    }

    public function test_the_versions_modal_does_not_warn_for_the_installed_version_or_other_mods(): void
    {
        $this->account();
        $this->fakeServer();

        $installed = ['id' => 'alpha-old', 'version_number' => 'old.0'] + self::version('alpha');
        $this->assertFalse($this->installVersionAction('alpha', 'Alpha', $installed)->isConfirmationRequired());
        $this->assertFalse($this->installVersionAction('beta', 'Beta', self::version('beta'))->isConfirmationRequired());
    }
}
