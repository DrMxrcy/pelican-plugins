<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/** Plan 8: category filter options come from Modrinth's tag list, cached and scoped to the page's project type. */
class CategoryTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    public function test_categories_are_fetched_once_cached_and_filtered_by_project_type(): void
    {
        Http::fake(['api.modrinth.com/v2/tag/category' => Http::response([
            ['icon' => '', 'name' => 'utility', 'project_type' => 'mod', 'header' => 'categories'],
            ['icon' => '', 'name' => 'optimization', 'project_type' => 'mod', 'header' => 'categories'],
            ['icon' => '', 'name' => 'cursed', 'project_type' => 'mod', 'header' => 'categories'],
            ['icon' => '', 'name' => 'economy', 'project_type' => 'plugin', 'header' => 'categories'],
            ['icon' => '', 'name' => 'blocks', 'project_type' => 'resourcepack', 'header' => 'categories'],
        ])]);
        $service = new MinecraftModrinthService();

        $this->assertSame(['optimization', 'utility'], $service->getCategories(ModrinthProjectType::Mod));
        $this->assertSame(['economy'], $service->getCategories(ModrinthProjectType::Plugin));
        Http::assertSentCount(1);
    }

    public function test_category_fetch_failure_is_not_cached(): void
    {
        Http::fake(['api.modrinth.com/v2/tag/category' => Http::sequence()
            ->push('boom', 500)
            ->push([['icon' => '', 'name' => 'utility', 'project_type' => 'mod', 'header' => 'categories']]),
        ]);
        $service = new MinecraftModrinthService();

        $failed = $service->getCategories(ModrinthProjectType::Mod);
        $retried = $service->getCategories(ModrinthProjectType::Mod);

        $this->assertSame([[], ['utility']], [$failed, $retried]);
    }

    public function test_categories_are_cached_for_an_hour(): void
    {
        Http::fake(['api.modrinth.com/v2/tag/category' => Http::sequence()
            ->push([['icon' => '', 'name' => 'utility', 'project_type' => 'mod', 'header' => 'categories']])
            ->push([['icon' => '', 'name' => 'storage', 'project_type' => 'mod', 'header' => 'categories']]),
        ]);
        $service = new MinecraftModrinthService();

        $first = $service->getCategories(ModrinthProjectType::Mod);
        $this->travel(59)->minutes();
        $cached = $service->getCategories(ModrinthProjectType::Mod);
        $this->travel(2)->minutes();
        $expired = $service->getCategories(ModrinthProjectType::Mod);

        $this->assertSame([['utility'], ['utility'], ['storage']], [$first, $cached, $expired]);
    }

    public function test_malformed_entries_are_skipped(): void
    {
        Http::fake(['api.modrinth.com/v2/tag/category' => Http::response([
            ['name' => 'utility', 'project_type' => 'mod'],
            ['name' => 7, 'project_type' => 'mod'],
            ['project_type' => 'mod'],
            'garbage',
        ])]);

        $this->assertSame(['utility'], (new MinecraftModrinthService())->getCategories(ModrinthProjectType::Mod));
    }
}
