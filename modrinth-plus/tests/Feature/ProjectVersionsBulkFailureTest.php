<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

/**
 * getProjectVersionsBulk() must tell "Modrinth has no compatible version" ([]) apart from
 * "the request failed" (null), so callers never treat an outage as incompatibility.
 */
class ProjectVersionsBulkFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    private function service(): MinecraftModrinthService
    {
        /** @var MinecraftModrinthService&MockInterface $service */
        $service = Mockery::mock(MinecraftModrinthService::class)->makePartial();
        $service->shouldReceive('getLoaderFromServer')->andReturn([
            'icon' => '',
            'name' => 'fabric',
            'supported_project_types' => ['mod'],
            'display_name' => 'Fabric',
        ]);
        $service->shouldReceive('getMinecraftVersion')->andReturn('26.3');

        return $service;
    }

    public function test_empty_list_and_failure_are_distinguished(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/empty/*' => Http::response([]),
            'api.modrinth.com/v2/project/has/*' => Http::response([['id' => 'v1', 'date_published' => '2026-01-01']]),
            'api.modrinth.com/v2/project/broken/*' => Http::response('oops', 500),
            'api.modrinth.com/v2/project/offline/*' => Http::failedConnection(),
        ]);

        $result = $this->service()->getProjectVersionsBulk(['empty', 'has', 'broken', 'offline'], new Server());

        $this->assertSame([], $result['empty']);
        $this->assertSame('v1', $result['has'][0]['id']);
        $this->assertArrayHasKey('broken', $result);
        $this->assertNull($result['broken']);
        $this->assertArrayHasKey('offline', $result);
        $this->assertNull($result['offline']);
    }

    public function test_failures_are_not_cached_but_empty_lists_are(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/empty/*' => Http::response([]),
            'api.modrinth.com/v2/project/broken/*' => Http::sequence()
                ->push('oops', 500)
                ->push([['id' => 'v2', 'date_published' => '2026-01-01']]),
        ]);
        $service = $this->service();

        $first = $service->getProjectVersionsBulk(['empty', 'broken'], new Server());
        $second = $service->getProjectVersionsBulk(['empty', 'broken'], new Server());

        $this->assertNull($first['broken']);
        $this->assertSame('v2', $second['broken'][0]['id']);
        $this->assertSame([], $second['empty']);
        // empty (once, then cached) + broken twice
        Http::assertSentCount(3);
    }
}
