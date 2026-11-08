<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Mockery;

/**
 * Plan 7: dependencies are recorded with `installed_as_dependency_of`, and rewriting the
 * metadata file never drops keys the plugin doesn't write itself (the official plugin ignores
 * unknown keys, so they are safe to keep).
 */
class DependencyMetadataTest extends TestCase
{
    /** Contents of the fake `.modrinth-metadata.json`. */
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getContent')->andReturnUsing(fn () => $this->file);
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) {
            $this->file = $content;

            return new Response(new Psr7Response(204));
        });

        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 1;

        return $server;
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(): array
    {
        return json_decode($this->file, true, flags: JSON_THROW_ON_ERROR)['installed_mods'];
    }

    public function test_a_dependency_is_recorded_with_the_project_that_required_it(): void
    {
        $this->file = json_encode(['installed_mods' => []], JSON_THROW_ON_ERROR);

        $saved = app(MinecraftModrinthService::class)->saveModMetadata(
            $this->server(), ModrinthProjectType::Mod, 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', '0.140.0', 'fabric-api.jar', null, 'xaeros-minimap',
        );

        $this->assertTrue($saved);
        $this->assertSame('xaeros-minimap', $this->entries()[0]['installed_as_dependency_of']);
        $this->assertArrayNotHasKey('author', $this->entries()[0]);
    }

    public function test_rewriting_an_entry_keeps_keys_it_does_not_write(): void
    {
        $this->file = json_encode(['installed_mods' => [
            [
                'project_id' => 'P7dR8mSH',
                'project_slug' => 'fabric-api',
                'project_title' => 'Fabric API',
                'version_id' => 'fapi1',
                'version_number' => '0.139.0',
                'filename' => 'fabric-api-old.jar',
                'installed_at' => '2026-01-01T00:00:00+00:00',
                'installed_as_dependency_of' => 'xaeros-minimap',
                'some_other_tool' => 'kept',
            ],
            [
                'project_id' => 'lithium',
                'project_slug' => 'lithium',
                'project_title' => 'Lithium',
                'version_id' => 'l1',
                'version_number' => '1.0',
                'filename' => 'lithium.jar',
                'installed_at' => '2026-01-01T00:00:00+00:00',
                'installed_as_dependency_of' => 'someone-else',
            ],
        ]], JSON_THROW_ON_ERROR);

        // A plain update of the dependency (no installed_as_dependency_of passed).
        app(MinecraftModrinthService::class)->saveModMetadata(
            $this->server(), ModrinthProjectType::Mod, 'P7dR8mSH', 'fabric-api', 'Fabric API', 'fapi2', '0.140.0', 'fabric-api-new.jar',
        );

        [$fabric, $lithium] = $this->entries();

        $this->assertSame('fapi2', $fabric['version_id']);
        $this->assertSame('fabric-api-new.jar', $fabric['filename']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $fabric['installed_at']);
        $this->assertSame('xaeros-minimap', $fabric['installed_as_dependency_of']);
        $this->assertSame('kept', $fabric['some_other_tool']);
        $this->assertSame('someone-else', $lithium['installed_as_dependency_of']);

        // Removing another entry keeps the extra keys too.
        app(MinecraftModrinthService::class)->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'lithium');

        $this->assertSame([$fabric], $this->entries());
    }
}
