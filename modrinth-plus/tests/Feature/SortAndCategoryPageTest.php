<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Enums\ModrinthSearchIndex;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Plan 8: the All tab's sort and category filters reach getProjects() and send the table back to page 1. */
class SortAndCategoryPageTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** @var array<int, array{page: int, index: ?ModrinthSearchIndex, categories: array<mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');

        [$user, $server] = $this->generateTestAccount();
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('1.21.1');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn(['game-mechanics', 'optimization', 'utility']);
        MinecraftModrinth::shouldReceive('getProjects')->andReturnUsing(function ($server, $type, int $page = 1, ?string $search = null, bool $includeClientOnly = false, ?ModrinthSearchIndex $index = null, array $categories = []) {
            $this->calls[] = ['page' => $page, 'index' => $index, 'categories' => $categories];

            return ['hits' => [], 'total_hits' => 100, 'versions' => []];
        });
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    private function pageOf(Testable $testable): MinecraftModrinthModPage
    {
        $instance = $testable->instance();
        $this->assertInstanceOf(MinecraftModrinthModPage::class, $instance);

        return $instance;
    }

    /** @return array{page: int, index: ?ModrinthSearchIndex, categories: array<mixed>} */
    private function lastCall(): array
    {
        $this->assertNotEmpty($this->calls, 'getProjects() was never called');

        return $this->calls[array_key_last($this->calls)];
    }

    public function test_no_filter_leaves_sort_to_the_service(): void
    {
        Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable();

        $this->assertSame(['page' => 1, 'index' => null, 'categories' => []], $this->lastCall());
    }

    public function test_sort_and_categories_are_passed_to_the_search(): void
    {
        Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'all')
            ->loadTable()
            ->filterTable('sort', 'downloads')
            ->filterTable('categories', ['optimization']);

        $this->assertSame(['page' => 1, 'index' => ModrinthSearchIndex::Downloads, 'categories' => ['optimization']], $this->lastCall());
    }

    public function test_changing_a_filter_goes_back_to_page_one(): void
    {
        $page = Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable();
        $page->call('gotoPage', 3, $this->pageOf($page)->getTablePaginationPageName());

        $this->assertSame(3, $this->lastCall()['page']);

        $page->filterTable('sort', 'newest');
        $this->assertSame(['page' => 1, 'index' => ModrinthSearchIndex::Newest, 'categories' => []], $this->lastCall());

        $page->call('gotoPage', 2, $this->pageOf($page)->getTablePaginationPageName());
        $page->filterTable('categories', ['utility']);
        $this->assertSame(['page' => 1, 'index' => ModrinthSearchIndex::Newest, 'categories' => ['utility']], $this->lastCall());
    }

    public function test_tampered_sort_value_falls_back_to_the_default(): void
    {
        Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'all')
            ->loadTable()
            ->set('tableFilters.sort', ['value' => 'nonsense']);

        $this->assertNull($this->lastCall()['index']);
    }

    public function test_category_options_come_from_modrinth_tags(): void
    {
        $page = Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'all')->loadTable();

        $filter = $this->pageOf($page)->getTable()->getFilter('categories');
        $this->assertInstanceOf(SelectFilter::class, $filter);
        $this->assertSame(
            ['game-mechanics' => 'Game Mechanics', 'optimization' => 'Optimization', 'utility' => 'Utility'],
            $filter->getOptions(),
        );
    }

    public function test_filters_are_hidden_on_the_installed_tab(): void
    {
        $page = Livewire::test(MinecraftModrinthModPage::class)->set('activeTab', 'installed')->loadTable();

        $this->assertSame([], $this->pageOf($page)->getTable()->getFilters());
    }

    /**
     * The filters form is built when the component boots, before the tab change is applied,
     * so without a rebuild the All tab rendered right after Installed had no filter fields.
     */
    public function test_filters_come_back_when_returning_to_the_all_tab(): void
    {
        $page = Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'installed')
            ->loadTable()
            ->set('activeTab', 'all');

        $page->assertSeeHtml('tableFilters.sort.value');
        $page->assertSeeHtml('tableFilters.categories.values');
        $this->assertCount(2, $this->pageOf($page)->getTableFiltersForm()->getComponents());
    }

    public function test_filters_leave_when_switching_to_the_installed_tab(): void
    {
        $page = Livewire::test(MinecraftModrinthModPage::class)
            ->set('activeTab', 'all')
            ->loadTable()
            ->set('activeTab', 'installed');

        $page->assertDontSeeHtml('tableFilters.sort.value');
    }

    public function test_sort_and_category_strings_exist_in_every_locale(): void
    {
        foreach (['en', 'de'] as $locale) {
            foreach (['filters.sort', 'filters.sort_default', 'filters.categories', 'sort.relevance', 'sort.downloads', 'sort.follows', 'sort.newest', 'sort.updated'] as $key) {
                $line = "modrinth-plus::strings.$key";
                $this->assertNotSame($line, trans($line, locale: $locale), "$locale: $key missing");
            }
        }
    }
}
