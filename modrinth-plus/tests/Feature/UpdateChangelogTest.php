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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 17: the update dialog shows the changelogs of the versions the update brings in, from
 * the versions the page already fetched, and the versions modal renders changelogs the same,
 * safe way. Versions come from the real service over a faked Modrinth API, so the request
 * count is the real one.
 */
class UpdateChangelogTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const ROW = '0';

    /** @var array<int, string> files pulled by the daemon */
    private array $downloads = [];

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

    /**
     * Alpha versions $count..1 (newest first), the installed one is $installed. Changelogs
     * default to "Notes for N.0.0".
     *
     * @param  array<int, ?string>  $changelogs  version number => changelog
     */
    private function fakeModrinth(int $count, int $installed, array $changelogs = []): void
    {
        $versions = [];
        for ($i = $count; $i >= 1; $i--) {
            $versions[] = [
                'id' => "alpha-v$i",
                'project_id' => 'alpha',
                'version_number' => "$i.0.0",
                'version_type' => 'release',
                'date_published' => sprintf('2026-01-%02dT00:00:00Z', $i),
                'changelog' => array_key_exists($i, $changelogs) ? $changelogs[$i] : "Notes for $i.0.0",
                'dependencies' => [],
                'files' => [['primary' => true, 'filename' => "alpha-$i.jar", 'url' => "https://cdn.modrinth.com/alpha-$i.jar"]],
            ];
        }

        Http::fake([
            'api.modrinth.com/v2/project/alpha/version*' => Http::response($versions),
            '*' => Http::response('unexpected request', 500),
        ]);

        $this->installed = [[
            'project_id' => 'alpha',
            'project_slug' => 'alpha',
            'project_title' => 'Alpha',
            'version_id' => "alpha-v$installed",
            'version_number' => "$installed.0.0",
            'filename' => "alpha-$installed.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]];

        // Everything that talks to the daemon is faked; version lookups run for real.
        MinecraftModrinth::partialMock();
        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(['name' => 'fabric']);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('readInstalledModsMetadata')->andReturnUsing(fn () => $this->installed);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([[
            'project_id' => 'alpha', 'slug' => 'alpha', 'title' => 'Alpha', 'description' => 'The Alpha mod',
            'author' => 'someone', 'downloads' => 10, 'date_modified' => '2026-01-01T00:00:00Z', 'icon_url' => null,
            'project_type' => 'mod', 'server_side' => 'required', 'client_side' => 'optional',
        ]]);
        MinecraftModrinth::shouldReceive('downloadFile')->andReturnUsing(function (Server $server, string $url, string $folder, string $filename) {
            $this->downloads[] = $filename;
        });
        MinecraftModrinth::shouldReceive('saveModMetadata')->andReturnUsing(
            function (Server $server, ModrinthProjectType $type, string $id, string $slug, string $title, string $versionId, string $versionNumber, string $filename) {
                $this->installed[0] = ['version_id' => $versionId, 'version_number' => $versionNumber, 'filename' => $filename] + $this->installed[0];

                return true;
            },
        );
        MinecraftModrinth::shouldReceive('deleteFile')->andReturnNull();
        MinecraftModrinth::shouldReceive('renameFile')->andReturnNull();
    }

    private function page(): Testable
    {
        return Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'installed')
            ->loadTable();
    }

    public function test_update_dialog_lists_the_changelogs_of_the_newer_versions_newest_first(): void
    {
        $this->fakeModrinth(5, 2);

        $page = $this->page()
            ->mountAction(TestAction::make('update')->table(self::ROW))
            ->assertMountedActionModalSee(['Notes for 5.0.0', 'Notes for 4.0.0', 'Notes for 3.0.0'])
            ->assertMountedActionModalDontSee(['Notes for 2.0.0', 'Notes for 1.0.0', 'more on Modrinth']);

        $html = $page->getMountedActionModalHtml();
        $positions = array_map(fn (string $text) => strpos($html, $text), ['Notes for 5.0.0', 'Notes for 4.0.0', 'Notes for 3.0.0']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'changelogs must be newest first');
    }

    public function test_confirming_the_dialog_updates_to_the_newest_version_as_before(): void
    {
        $this->fakeModrinth(5, 2);

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::ROW))
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(['alpha-5.jar'], $this->downloads);
        $this->assertSame('alpha-v5', $this->installed[0]['version_id']);
    }

    public function test_opening_the_dialog_sends_no_extra_modrinth_request(): void
    {
        $this->fakeModrinth(5, 2);

        $page = $this->page();
        $sent = count(Http::recorded());
        $this->assertGreaterThan(0, $sent, 'the page itself fetched the versions');

        // Every Livewire request re-renders the table, which looks the versions up once (memoised
        // for the request). With the version cache cleared, any lookup the dialog made outside
        // that memo would show up as a second request.
        cache()->flush();
        $page->call('$refresh');
        $perRender = count(Http::recorded()) - $sent;
        $this->assertSame(1, $perRender);

        cache()->flush();
        $before = count(Http::recorded());
        $page->mountAction(TestAction::make('update')->table(self::ROW))
            ->assertMountedActionModalSee('Notes for 5.0.0');

        Http::assertSentCount($before + $perRender);
    }

    public function test_hostile_changelog_html_never_reaches_the_dialog(): void
    {
        $this->fakeModrinth(3, 1, [
            3 => "<script>alert('xss')</script>\n\n<img src=x onerror=\"alert(1)\">\n\n[click me](javascript:alert(1)) **safe bold**",
        ]);

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::ROW))
            ->assertMountedActionModalSeeHtml('<strong>safe bold</strong>')
            ->assertMountedActionModalDontSeeHtml(['<script>alert', 'onerror=', 'href="javascript:', '<img src="x"', '<img src=x']);
    }

    public function test_empty_or_missing_changelog_shows_the_fallback(): void
    {
        $this->fakeModrinth(3, 1, [3 => '', 2 => null]);

        $this->page()
            ->mountAction(TestAction::make('update')->table(self::ROW))
            ->assertMountedActionModalSee(['3.0.0', '2.0.0', trans('modrinth-plus::strings.changelog.none')]);
    }

    public function test_more_than_ten_newer_versions_show_ten_and_a_link_to_the_rest(): void
    {
        $this->fakeModrinth(14, 1, [14 => '[admin](/admin) ![shot](https://cdn.example.com/s.png)']);

        $page = $this->page()
            ->mountAction(TestAction::make('update')->table(self::ROW))
            ->assertMountedActionModalSee(['admin', 'Notes for 5.0.0', trans('modrinth-plus::strings.changelog.more', ['count' => 3])])
            ->assertMountedActionModalDontSee(['Notes for 4.0.0', 'Notes for 2.0.0'])
            ->assertMountedActionModalSeeHtml(['href="https://modrinth.com/mod/alpha/versions"', 'max-width: 100%'])
            ->assertMountedActionModalDontSeeHtml('href="/admin"');

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $page->getMountedActionModalHtml());
    }

    public function test_versions_modal_renders_changelogs_safely_with_a_fallback(): void
    {
        $this->fakeModrinth(3, 1, [3 => '- Added **dragons** <script>alert(1)</script>', 2 => '']);

        $this->page()
            ->mountAction(TestAction::make('versions')->table(self::ROW))
            ->assertMountedActionModalSeeHtml('<strong>dragons</strong>')
            ->assertMountedActionModalDontSeeHtml('<script>alert')
            ->assertMountedActionModalSee(trans('modrinth-plus::strings.changelog.none'));
    }
}
