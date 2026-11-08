<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Plan 14: every metadata writer reads the file again under the lock and writes nothing when
 * that read fails. Before, a daemon error read as "no mods installed" and the write replaced
 * `.modrinth-metadata.json` with only the new entry, losing every other tracked mod.
 */
class MetadataReadFailureTest extends TestCase
{
    private const PATH = 'mods/.modrinth-metadata.json';

    /** Contents of the fake metadata file; null = it doesn't exist. */
    private ?string $file = null;

    /** Thrown by the fake daemon when the metadata file is read. */
    private ?Exception $readError = null;

    /** @var array<string, string> jar name => contents */
    private array $jars = [];

    /** @var array<string, true> jars whose read fails */
    private array $unreadableJars = [];

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getDirectory')->with('mods')->andReturnUsing(fn () => array_map(
            fn (string $name) => ['name' => $name, 'size' => strlen($this->jars[$name]), 'file' => true, 'directory' => false],
            array_keys($this->jars),
        ));
        $repository->shouldReceive('getContent')->andReturnUsing(function (string $path) {
            if ($path !== self::PATH) {
                $name = basename($path);

                return isset($this->unreadableJars[$name]) ? throw new Exception('read timed out') : $this->jars[$name];
            }

            if ($this->readError) {
                throw $this->readError;
            }

            return $this->file ?? throw new FileNotFoundException('no metadata yet');
        });
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) {
            $this->assertSame(self::PATH, $path);
            $this->file = $content;
            $this->writes[] = $content;

            return new Response(new Psr7Response(204));
        });
        $repository->shouldReceive('deleteFiles')->never();

        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 1;

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        return app(MinecraftModrinthService::class);
    }

    /** @return array<string, mixed> */
    private static function entry(string $id): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => ucfirst($id),
            'version_id' => "$id-1",
            'version_number' => '1.0',
            'filename' => "$id.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    private function threeTrackedMods(): void
    {
        $this->file = json_encode(['installed_mods' => [self::entry('sodium'), self::entry('lithium'), self::entry('iris')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function save(string $id = 'fabric-api'): bool
    {
        return $this->service()->saveModMetadata($this->server(), ModrinthProjectType::Mod, $id, $id, ucfirst($id), "$id-1", '1.0', "$id.jar");
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) $this->file, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_save_writes_nothing_when_the_metadata_cannot_be_read(): void
    {
        $this->threeTrackedMods();
        $before = $this->file;
        $this->readError = new Exception('cURL error 28: connection timed out');

        $this->assertFalse($this->save());
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->file);
    }

    public function test_remove_writes_nothing_when_the_metadata_cannot_be_read(): void
    {
        $this->threeTrackedMods();
        $before = $this->file;
        $this->readError = new Exception('daemon unreachable');

        $this->assertFalse($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'lithium'));
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->file);
    }

    public function test_a_missing_file_is_created_by_the_first_install(): void
    {
        $this->assertTrue($this->save());

        $this->assertCount(1, $this->writes);
        $this->assertSame(['installed_mods'], array_keys($this->document()));
        $this->assertSame(['fabric-api'], array_column($this->document()['installed_mods'], 'project_id'));
    }

    public function test_an_empty_file_counts_as_no_mods_installed(): void
    {
        $this->file = '';

        $this->assertTrue($this->save());
        $this->assertSame(['fabric-api'], array_column($this->document()['installed_mods'], 'project_id'));
    }

    public function test_a_successful_save_keeps_every_entry_in_the_same_format(): void
    {
        $this->threeTrackedMods();

        $this->assertTrue($this->save());

        $this->assertSame(['sodium', 'lithium', 'iris', 'fabric-api'], array_column($this->document()['installed_mods'], 'project_id'));
        $this->assertSame(self::entry('lithium'), $this->document()['installed_mods'][1]);
        // Same encoding as before (and as the official plugin): pretty printed, unescaped slashes.
        $this->assertSame(json_encode($this->document(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $this->file);
    }

    public function test_writes_keep_entries_and_keys_the_plugin_does_not_understand(): void
    {
        $partial = ['project_id' => 'from-other-tool', 'filename' => 'other.jar'];
        $this->file = json_encode([
            'installed_mods' => [self::entry('sodium'), $partial],
            'written_by' => 'another tool',
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue($this->save());
        $this->assertSame([self::entry('sodium'), $partial], array_slice($this->document()['installed_mods'], 0, 2));
        $this->assertSame('another tool', $this->document()['written_by']);

        $this->assertTrue($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'sodium'));
        $this->assertSame([$partial], array_slice($this->document()['installed_mods'], 0, 1));
        $this->assertSame(['from-other-tool', 'fabric-api'], array_column($this->document()['installed_mods'], 'project_id'));
        $this->assertSame('another tool', $this->document()['written_by']);
    }

    public function test_an_unparsable_file_is_never_overwritten(): void
    {
        foreach (['{"installed_mods": [', '"just a string"', '{"installed_mods": "nope"}', '[1, 2]'] as $content) {
            $this->file = $content;

            $this->assertFalse($this->save(), "Overwrote: $content");
            $this->assertFalse($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'sodium'), "Overwrote: $content");
            $this->assertSame($content, $this->file);
        }

        $this->assertSame([], $this->writes);
    }

    public function test_scan_import_writes_nothing_when_the_metadata_cannot_be_read(): void
    {
        $this->threeTrackedMods();
        $before = $this->file;
        $this->readError = new Exception('daemon unreachable');
        $this->jars = ['sodium.jar' => 'sodium bytes', 'homemade.jar' => 'homemade bytes'];
        UnmatchedJars::remember(1, ModrinthProjectType::Mod, ['homemade.jar' => strlen('homemade bytes')]);
        Http::fake();

        $result = $this->service()->scanAndImport($this->server(), ModrinthProjectType::Mod);

        $this->assertTrue($result['error']);
        $this->assertSame([], $result['imported']);
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->file);
        // Without the metadata no jar can be told tracked or untracked: nothing is looked up or forgotten.
        Http::assertNothingSent();
        $this->assertSame(['homemade.jar' => strlen('homemade bytes')], UnmatchedJars::remembered(1, ModrinthProjectType::Mod));
    }

    public function test_scan_import_writes_nothing_when_the_read_under_the_lock_fails(): void
    {
        // The first read (finding untracked jars) works; the one under the lock fails.
        $this->threeTrackedMods();
        $before = $this->file;
        $this->jars = ['fabric-api.jar' => 'fabric bytes'];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/version_files')) {
                $this->readError = new Exception('daemon went away');

                return Http::response([sha1('fabric bytes') => ['id' => 'f1', 'project_id' => 'P7dR8mSH', 'version_number' => '1.0']]);
            }

            return Http::response([['id' => 'P7dR8mSH', 'slug' => 'fabric-api', 'title' => 'Fabric API']]);
        });

        $result = $this->service()->scanAndImport($this->server(), ModrinthProjectType::Mod);

        $this->assertTrue($result['error']);
        $this->assertSame([], $result['imported']);
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->file);
    }
}
