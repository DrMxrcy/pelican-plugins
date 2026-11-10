<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Pages\ModpacksPage;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackSizes;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 38: the install confirm modal shows the pack size against the server's free space,
 * and Install is disabled with the reason for a version known not to fit. The node is only
 * asked from the modal / button, never when the pack list loads.
 */
class DiskBudgetPageTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const GIB = 1024 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Filament::setCurrentPanel('server');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    private function actingOn(int $diskMb, ?int $used): Server
    {
        [$user, $server] = $this->scenarioServer();
        $server->forceFill(['disk' => $diskMb])->save();
        $this->disk->used = $used;
        $this->actingAs($user);
        Filament::setTenant($server->fresh());

        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        Http::swap(new HttpFactory());
        Http::fake([
            'api.modrinth.com/v2/project/'.Mrpack::PACK_ID.'/version' => Http::response([Mrpack::version($archive)]),
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/project/'.Mrpack::PACK_ID => Http::response([
                'id' => Mrpack::PACK_ID,
                'slug' => 'test-pack',
                'title' => 'Test Pack',
                'project_type' => 'modpack',
                'description' => 'desc',
                'body' => 'body',
                'icon_url' => null,
                'gallery' => [],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([['name' => 'optimization', 'project_type' => 'modpack', 'header' => 'categories']]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [[
                'project_id' => Mrpack::PACK_ID,
                'slug' => 'test-pack',
                'title' => 'Test Pack',
                'description' => 'desc',
                'author' => 'someone',
                'downloads' => 5,
                'icon_url' => null,
                'categories' => ['fabric'],
                'versions' => ['1.20.1'],
                'date_modified' => '2026-01-01T00:00:00Z',
                'project_type' => 'modpack',
            ]], 'total_hits' => 1]),
        ]);

        return $server;
    }

    /** The Install action of the first version, as the details modal builds it. */
    private function installAction(): Action
    {
        /** @var ModpacksPage $instance */
        $instance = Livewire::test(ModpacksPage::class)->instance();
        $components = (new \ReflectionMethod($instance, 'detailSchema'))->invoke($instance, Mrpack::PACK_ID);
        $schema = Schema::make($instance)->components($components);

        $found = null;
        $walk = function (array $components) use (&$walk, &$found): void {
            foreach ($components as $component) {
                if ($component instanceof Section) {
                    foreach ($component->getHeaderActions() as $action) {
                        if ($action->getName() === 'install_0') {
                            $found = $action;
                        }
                    }
                    $walk($component->getChildSchema()?->getComponents(withHidden: true) ?? []);
                }
            }
        };
        $walk($schema->getComponents(withHidden: true));
        $this->assertInstanceOf(Action::class, $found);

        return $found;
    }

    private function description(Action $action): string
    {
        return html_entity_decode(strip_tags((string) $action->getModalDescription()), ENT_QUOTES);
    }

    public function test_loading_the_pack_list_never_calls_the_daemon(): void
    {
        $this->actingOn(10 * 1024, 3 * self::GIB);

        // The table loads deferred: render it with its records as the browser would.
        Livewire::test(ModpacksPage::class)->assertOk()->call('loadTable')->assertSee('Test Pack');

        $this->assertSame(0, $this->disk->calls);
    }

    public function test_modal_shows_pack_size_and_free_space_for_a_pack_that_fits(): void
    {
        $this->actingOn(10 * 1024, 3 * self::GIB);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 5 * self::GIB);

        $action = $this->installAction();

        $this->assertFalse($action->isDisabled());
        $this->assertSame(trans('modpacks-plus::strings.actions.install'), (string) $action->getLabel());
        $this->assertNull($action->getTooltip());
        $description = $this->description($action);
        $this->assertStringContainsString('Pack size: 5 GB.', $description);
        $this->assertStringContainsString('Free disk space: 7 GB of 10 GB (1 GB is always kept free).', $description);
        // One lazy call for the whole modal, however many closures read the budget.
        $this->assertSame(1, $this->disk->calls);
    }

    public function test_install_is_disabled_with_the_reason_for_a_pack_that_cannot_fit(): void
    {
        $this->actingOn(10 * 1024, 3 * self::GIB);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 7 * self::GIB);

        $action = $this->installAction();

        $this->assertTrue($action->isDisabled());
        $this->assertSame(trans('modpacks-plus::strings.install.no_room'), (string) $action->getLabel());
        $this->assertStringContainsString('needs at least 8 GB, 7 GB free of 10 GB', (string) $action->getTooltip());
        $this->assertStringContainsString('needs at least 8 GB, 7 GB free of 10 GB', $this->description($action));
    }

    /** Live 2026-11-10: the redis cache hands the stored size back as a numeric string. */
    public function test_a_size_the_cache_returns_as_a_numeric_string_still_disables_install(): void
    {
        $this->actingOn(10 * 1024, 3 * self::GIB);
        $key = (new \ReflectionMethod(PackSizes::class, 'key'))->invoke(null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID);
        Cache::put($key, (string) (7 * self::GIB), 60);

        $this->assertSame(7 * self::GIB, PackSizes::known(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID));
        $action = $this->installAction();
        $this->assertTrue($action->isDisabled());
        $this->assertStringContainsString('needs at least 8 GB, 7 GB free of 10 GB', (string) $action->getTooltip());
    }

    public function test_a_cached_size_that_is_not_a_plain_number_is_ignored(): void
    {
        $key = (new \ReflectionMethod(PackSizes::class, 'key'))->invoke(null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID);
        foreach (['-5', '7e9', ' 7', '7.5', '', '99999999999999999999', 'abc'] as $junk) {
            Cache::put($key, $junk, 60);
            $this->assertNull(PackSizes::known(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID), $junk);
        }
    }

    public function test_without_a_known_size_the_download_size_is_shown_and_install_stays_enabled(): void
    {
        $this->actingOn(10 * 1024, 3 * self::GIB);

        $action = $this->installAction();

        $this->assertFalse($action->isDisabled());
        $this->assertStringContainsString('Pack download:', $this->description($action));
        $this->assertStringContainsString('The full size is checked before anything changes.', $this->description($action));
    }

    public function test_unlimited_disk_shows_the_configured_cap_and_never_calls_the_daemon(): void
    {
        $this->actingOn(0, 3 * self::GIB);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 7 * self::GIB);

        $action = $this->installAction();

        $this->assertFalse($action->isDisabled());
        $this->assertStringContainsString('no disk limit; packs up to 20 GB can be installed', $this->description($action));
        $this->assertSame(0, $this->disk->calls);
    }

    public function test_a_pack_over_the_configured_cap_is_disabled_on_an_unlimited_disk(): void
    {
        config()->set('modpacks-plus.caps.max_total_bytes', 4 * self::GIB);
        $this->actingOn(0, null);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 5 * self::GIB);

        $action = $this->installAction();

        $this->assertTrue($action->isDisabled());
        $this->assertStringContainsString('over the configured limit of 4 GB', (string) $action->getTooltip());
    }

    public function test_unknown_usage_says_so_and_does_not_block(): void
    {
        $this->actingOn(10 * 1024, null);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 7 * self::GIB);

        $action = $this->installAction();

        $this->assertFalse($action->isDisabled());
        $this->assertStringContainsString('could not be read right now; the configured limit of 20 GB applies', $this->description($action));
    }

    public function test_german_modal_text(): void
    {
        app()->setLocale('de');
        $this->actingOn(10 * 1024, 3 * self::GIB);
        PackSizes::remember(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 7 * self::GIB);

        $action = $this->installAction();

        $this->assertStringContainsString('braucht mindestens 8 GB, frei sind 7 GB von 10 GB', (string) $action->getTooltip());
        $this->assertStringContainsString('Freier Speicher: 7 GB von 10 GB', $this->description($action));
    }
}
