<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 9: the "Check compatibility" header action lists installed projects without a build
 * for the server's current Minecraft version + loader. It is read-only.
 */
class CompatibilityCheckTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.4');
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn(['hits' => [], 'total_hits' => 0, 'versions' => []]);

        // Fails if: the check modifies files or metadata.
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

    /** @param  array<int, SubuserPermission>  $permissions  empty = the server owner */
    private function account(array $permissions = []): void
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);
    }

    private function withLoader(): void
    {
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn([
            'icon' => '',
            'name' => 'fabric',
            'supported_project_types' => ['mod'],
            'display_name' => 'Fabric',
        ]);
    }

    /** @param  array<int, string>  $ids */
    private function installed(array $ids): void
    {
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn(array_map(fn (string $id) => [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => "Title of $id",
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => "$id-1.0.0.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ], $ids));
    }

    /** @return array{id: string, version_number: string} */
    private function version(string $id, string $number): array
    {
        return ['id' => $id, 'version_number' => $number];
    }

    private function check(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)->mountAction('check_compatibility');
    }

    public function test_lists_exactly_the_projects_that_need_attention(): void
    {
        $this->account();
        $this->withLoader();
        $this->installed(['lithium', 'sodium', 'gone', 'failed']);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')
            ->once()
            ->withArgs(fn (array $ids) => $ids === ['lithium', 'sodium', 'gone', 'failed'])
            ->andReturn([
                'lithium' => [$this->version('v1', '1.0.0')],
                'sodium' => [$this->version('v9', '9.9.9'), $this->version('v8', '8.0.0')],
                'gone' => [],
                'failed' => null,
            ]);

        $this->check()
            ->assertActionMounted('check_compatibility')
            ->assertMountedActionModalSee('Minecraft 1.21.4 with Fabric')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.summary', ['count' => 3, 'total' => 4]))
            ->assertMountedActionModalDontSee('Title of lithium')
            ->assertMountedActionModalSee('Title of sodium')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.needs_update'))
            ->assertMountedActionModalSee('9.9.9')
            ->assertMountedActionModalSee('Title of gone')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.incompatible'))
            ->assertMountedActionModalSee('Title of failed')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.unknown'))
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.details.unknown'))
            ->assertMountedActionModalDontSee(trans('modrinth-plus::strings.compatibility.all_compatible', ['count' => 4]));
    }

    public function test_all_compatible_says_so(): void
    {
        $this->account();
        $this->withLoader();
        $this->installed(['lithium', 'sodium']);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn([
            'lithium' => [$this->version('v1', '1.0.0')],
            'sodium' => [$this->version('v2', '2.0.0'), $this->version('v1', '1.0.0')],
        ]);

        $this->check()
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.all_compatible', ['count' => 2]))
            ->assertMountedActionModalDontSee('Title of lithium')
            ->assertMountedActionModalDontSee(trans('modrinth-plus::strings.compatibility.status.incompatible'));
    }

    public function test_unknown_loader_reports_unknown_without_asking_modrinth(): void
    {
        $this->account();
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        $this->installed(['lithium']);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->never();

        $this->check()
            ->assertMountedActionModalSee('Title of lithium')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.unknown'))
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.details.unknown_loader'))
            ->assertMountedActionModalDontSee(trans('modrinth-plus::strings.compatibility.status.incompatible'));
    }

    public function test_nothing_installed(): void
    {
        $this->account();
        $this->withLoader();
        $this->installed([]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->never();

        $this->check()->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.none_installed'));
    }

    public function test_project_title_from_metadata_is_escaped(): void
    {
        $this->account();
        $this->withLoader();
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([[
            'project_id' => 'evil',
            'project_slug' => 'evil',
            'project_title' => '<img src=x onerror=alert(1)>',
            'version_id' => 'v1',
            'version_number' => '<b>1</b>',
            'filename' => 'evil.jar',
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn(['evil' => []]);

        $this->check()
            ->assertMountedActionModalDontSeeHtml('<img src=x onerror=alert(1)>')
            ->assertMountedActionModalDontSeeHtml('<b>1</b>')
            ->assertMountedActionModalSeeHtml(e('<img src=x onerror=alert(1)>'));
    }

    public function test_read_only_subuser_can_run_the_check(): void
    {
        $this->account([SubuserPermission::FileRead]);
        $this->withLoader();
        $this->installed(['gone']);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn(['gone' => []]);

        $this->check()
            ->assertActionMounted('check_compatibility')
            ->assertMountedActionModalSee('Title of gone')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.incompatible'));
    }

    public function test_german_strings(): void
    {
        app()->setLocale('de');
        $this->account();
        $this->withLoader();
        $this->installed(['gone']);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn(['gone' => []]);

        $this->assertNotSame(
            trans('modrinth-plus::strings.compatibility.status.incompatible', locale: 'en'),
            trans('modrinth-plus::strings.compatibility.status.incompatible'),
        );

        $this->check()->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.status.incompatible'));
    }

    /** Plan 18: pinned projects are still listed, labelled as pinned, without "use the update button". */
    public function test_pinned_projects_are_listed_and_labelled(): void
    {
        $this->account();
        $this->withLoader();
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([
            ['project_id' => 'sodium', 'project_slug' => 'sodium', 'project_title' => 'Title of sodium', 'version_id' => 'v1', 'version_number' => '1.0.0', 'filename' => 'sodium.jar', 'installed_at' => '2026-01-01T00:00:00+00:00', 'pinned' => true],
            ['project_id' => 'gone', 'project_slug' => 'gone', 'project_title' => 'Title of gone', 'version_id' => 'v1', 'version_number' => '1.0.0', 'filename' => 'gone.jar', 'installed_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        MinecraftModrinth::shouldReceive('getProjectVersionsBulk')->andReturn([
            'sodium' => [$this->version('v9', '9.9.9')],
            'gone' => [],
        ]);

        $this->check()
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.summary', ['count' => 2, 'total' => 2]))
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.pinned_label', ['name' => 'Title of sodium']))
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.compatibility.details.needs_update_pinned', ['installed' => '1.0.0', 'newest' => '9.9.9']))
            ->assertMountedActionModalDontSee(trans('modrinth-plus::strings.compatibility.pinned_label', ['name' => 'Title of gone']));
    }
}
