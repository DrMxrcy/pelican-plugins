<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

class ModrinthUserAgentTest extends TestCase
{
    private function expectedUserAgent(): string
    {
        /** @var array{version: string} $manifest */
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        return 'DrMxrcy/pelican-plugins-modrinth-plus/' . $manifest['version'] . ' (github.com/DrMxrcy/pelican-plugins)';
    }

    /** Service with the server-derived loader/version stubbed so no database is needed. */
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
        $service->shouldReceive('getMinecraftVersion')->andReturn('1.21.1');

        return $service;
    }

    private function assertModrinthRequestSent(string $path): void
    {
        $userAgent = $this->expectedUserAgent();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.modrinth.com/v2/' . $path)
            && $request->hasHeader('User-Agent', $userAgent));
    }

    public function test_user_agent_identifies_plugin_without_email(): void
    {
        $this->assertStringNotContainsString('@', $this->expectedUserAgent());
    }

    public function test_search_request_sends_user_agent(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        $this->service()->getProjects(new Server(), ModrinthProjectType::Mod, 1, 'sodium');

        $this->assertModrinthRequestSent('search?');
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['query'] ?? null) === 'sodium' && ($query['limit'] ?? null) === '20';
        });
    }

    public function test_project_versions_request_sends_user_agent(): void
    {
        Http::fake(['api.modrinth.com/v2/project/*' => Http::response([])]);

        $this->service()->getProjectVersions('AANobbMI', new Server());

        $this->assertModrinthRequestSent('project/AANobbMI/version?');
    }

    public function test_pooled_project_versions_requests_send_user_agent(): void
    {
        Http::fake(['api.modrinth.com/v2/project/*' => Http::response([])]);

        $result = $this->service()->getProjectVersionsBulk(['AANobbMI', 'P7dR8mSH'], new Server());

        $this->assertSame(['AANobbMI' => [], 'P7dR8mSH' => []], $result);
        $this->assertModrinthRequestSent('project/AANobbMI/version?');
        $this->assertModrinthRequestSent('project/P7dR8mSH/version?');
        Http::assertSentCount(2);
    }
}
