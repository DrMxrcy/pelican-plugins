<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Plan 11: "Scan folder" finds jars the metadata file doesn't list, looks them up on Modrinth
 * by SHA-1 in one request, records the matches with `imported: true` and remembers the rest as
 * "Not on Modrinth". It never deletes, renames or rewrites a jar.
 */
class ScanAndImportTest extends TestCase
{
    /** Contents of the fake `mods/.modrinth-metadata.json`. */
    private string $metadata = '';

    /** @var array<string, string> jar name => contents */
    private array $jars = [];

    /** @var list<string> paths whose contents were read */
    private array $reads = [];

    private int $metadataWrites = 0;

    /** @var array<string, int> jar name => size the listing reports instead of the real one */
    private array $sizeOverride = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->metadata = json_encode(['installed_mods' => []], JSON_THROW_ON_ERROR);

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getDirectory')->with('mods')->andReturnUsing(fn () => $this->listing());
        $repository->shouldReceive('getContent')->andReturnUsing(function (string $path) {
            if ($path === 'mods/.modrinth-metadata.json') {
                return $this->metadata;
            }

            $this->reads[] = $path;

            return $this->jars[basename($path)];
        });
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) {
            $this->assertSame('mods/.modrinth-metadata.json', $path, 'A scan only ever writes the metadata file.');
            $this->metadata = $content;
            $this->metadataWrites++;

            return new Response(new Psr7Response(204));
        });

        // Fails if: a scan deletes or renames any file.
        $repository->shouldReceive('deleteFiles')->never();
        $repository->shouldReceive('renameFiles')->never();
        $repository->shouldReceive('copyFile')->never();
        $repository->shouldReceive('pull')->never();

        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    /** @return list<array<string, mixed>> */
    private function listing(): array
    {
        $files = [
            ['name' => '.modrinth-metadata.json', 'size' => strlen($this->metadata), 'file' => true, 'directory' => false],
            ['name' => 'config', 'size' => 0, 'file' => false, 'directory' => true],
        ];

        foreach ($this->jars as $name => $content) {
            $files[] = ['name' => $name, 'size' => $this->sizeOverride[$name] ?? strlen($content), 'file' => true, 'directory' => false, 'modified' => '2026-11-01T00:00:00Z'];
        }

        return $files;
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 1;

        return $server;
    }

    /** @return array<string, mixed> */
    private function version(string $id, string $projectId, string $number, string $jar): array
    {
        return [
            'id' => $id,
            'project_id' => $projectId,
            'version_number' => $number,
            'files' => [['primary' => true, 'filename' => $jar, 'hashes' => ['sha1' => sha1($this->jars[$jar])]]],
        ];
    }

    /**
     * Fakes Modrinth: `version_files` answers for the given jars, `projects` with titles.
     *
     * @param  array<string, array<string, mixed>>  $versionsByJar  jar name => version
     */
    private function fakeModrinth(array $versionsByJar): void
    {
        $byHash = [];
        foreach ($versionsByJar as $jar => $version) {
            $byHash[sha1($this->jars[$jar])] = $version;
        }

        Http::fake([
            'api.modrinth.com/v2/version_files' => Http::response($byHash === [] ? '{}' : $byHash),
            'api.modrinth.com/v2/projects*' => Http::response([
                ['id' => 'AANobbMI', 'slug' => 'sodium', 'title' => 'Sodium'],
                ['id' => 'gvQqBUqZ', 'slug' => 'lithium', 'title' => 'Lithium'],
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return json_decode($this->metadata, true, flags: JSON_THROW_ON_ERROR)['installed_mods'];
    }

    /** @return array{imported: list<string>, not_on_modrinth: list<string>, skipped: list<string>, duplicates: list<string>, error: bool} */
    private function scan(): array
    {
        return app(MinecraftModrinthService::class)->scanAndImport($this->server(), ModrinthProjectType::Mod);
    }

    public function test_an_untracked_modrinth_jar_is_imported_with_one_bulk_hash_lookup(): void
    {
        $this->jars = ['sodium-0.6.0.jar' => 'sodium bytes', 'homemade.jar' => 'homemade bytes'];
        $this->fakeModrinth(['sodium-0.6.0.jar' => $this->version('sod6', 'AANobbMI', '0.6.0', 'sodium-0.6.0.jar')]);

        $result = $this->scan();

        $this->assertSame(['Sodium'], $result['imported']);
        $this->assertSame(['homemade.jar'], $result['not_on_modrinth']);
        $this->assertFalse($result['error']);

        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('AANobbMI', $entries[0]['project_id']);
        $this->assertSame('sodium', $entries[0]['project_slug']);
        $this->assertSame('Sodium', $entries[0]['project_title']);
        $this->assertSame('sod6', $entries[0]['version_id']);
        $this->assertSame('0.6.0', $entries[0]['version_number']);
        $this->assertSame('sodium-0.6.0.jar', $entries[0]['filename']);
        $this->assertTrue($entries[0]['imported']);
        $this->assertArrayHasKey('installed_at', $entries[0]);

        // The imported entry is a normal, valid metadata entry (update/uninstall read it).
        $installed = app(MinecraftModrinthService::class)->getInstalledModsMetadata($this->server(), ModrinthProjectType::Mod);
        $this->assertSame(['AANobbMI'], array_column($installed, 'project_id'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.modrinth.com/v2/version_files'
            && $request['algorithm'] === 'sha1'
            && $request['hashes'] === [sha1('sodium bytes'), sha1('homemade bytes')]);
    }

    public function test_a_jar_not_on_modrinth_is_remembered_but_never_imported(): void
    {
        $this->jars = ['homemade.jar' => 'homemade bytes'];
        $this->fakeModrinth([]);

        $result = $this->scan();

        $this->assertSame([], $result['imported']);
        $this->assertSame(['homemade.jar'], $result['not_on_modrinth']);
        $this->assertSame([], $this->entries());
        $this->assertSame(0, $this->metadataWrites);
        $this->assertSame(['homemade.jar' => strlen('homemade bytes')], UnmatchedJars::remembered(1, ModrinthProjectType::Mod));
    }

    public function test_rerunning_the_scan_is_idempotent(): void
    {
        $this->jars = ['sodium-0.6.0.jar' => 'sodium bytes', 'homemade.jar' => 'homemade bytes'];
        $this->fakeModrinth(['sodium-0.6.0.jar' => $this->version('sod6', 'AANobbMI', '0.6.0', 'sodium-0.6.0.jar')]);

        $this->scan();
        $afterFirst = $this->metadata;
        $this->reads = [];

        $second = $this->scan();

        $this->assertSame([], $second['imported']);
        $this->assertSame(['homemade.jar'], $second['not_on_modrinth']);
        $this->assertSame($afterFirst, $this->metadata, 'A second scan must not add duplicates.');
        $this->assertSame(1, $this->metadataWrites);
        $this->assertCount(1, $this->entries());
        // The imported jar is tracked now, so only the unknown one is hashed again.
        $this->assertSame(['mods/homemade.jar'], $this->reads);
    }

    public function test_tracked_jars_are_neither_read_nor_looked_up(): void
    {
        $this->jars = ['Sodium-0.6.0.jar' => 'sodium bytes'];
        $this->metadata = json_encode(['installed_mods' => [[
            'project_id' => 'AANobbMI',
            'project_slug' => 'sodium',
            'project_title' => 'Sodium',
            'version_id' => 'sod6',
            'version_number' => '0.6.0',
            'filename' => 'sodium-0.6.0.jar',
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]]], JSON_THROW_ON_ERROR);
        Http::fake();

        $result = $this->scan();

        $this->assertSame(['imported' => [], 'not_on_modrinth' => [], 'skipped' => [], 'duplicates' => [], 'error' => false], $result);
        $this->assertSame([], $this->reads);
        Http::assertNothingSent();
    }

    public function test_a_second_jar_of_an_already_tracked_project_is_not_imported(): void
    {
        $this->jars = ['sodium-0.5.0.jar' => 'old sodium bytes'];
        $this->metadata = json_encode(['installed_mods' => [[
            'project_id' => 'AANobbMI',
            'project_slug' => 'sodium',
            'project_title' => 'Sodium',
            'version_id' => 'sod6',
            'version_number' => '0.6.0',
            'filename' => 'sodium-0.6.0.jar',
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ]]], JSON_THROW_ON_ERROR);
        $before = $this->metadata;
        $this->fakeModrinth(['sodium-0.5.0.jar' => $this->version('sod5', 'AANobbMI', '0.5.0', 'sodium-0.5.0.jar')]);

        $result = $this->scan();

        $this->assertSame([], $result['imported']);
        $this->assertSame(['sodium-0.5.0.jar'], $result['duplicates']);
        $this->assertSame([], $result['not_on_modrinth']);
        $this->assertSame($before, $this->metadata);
    }

    public function test_jars_over_the_size_cap_are_skipped_without_being_read(): void
    {
        $this->jars = ['huge.jar' => 'pretend this is big', 'lithium.jar' => 'lithium bytes'];
        $this->sizeOverride = ['huge.jar' => MinecraftModrinthService::SCAN_MAX_FILE_BYTES + 1];
        $this->fakeModrinth(['lithium.jar' => $this->version('lit1', 'gvQqBUqZ', '0.14.0', 'lithium.jar')]);

        $result = $this->scan();

        $this->assertSame(['huge.jar'], $result['skipped']);
        $this->assertSame(['Lithium'], $result['imported']);
        $this->assertSame(['mods/lithium.jar'], $this->reads);
    }

    public function test_unsafe_file_names_are_skipped(): void
    {
        $this->jars = ['bad..name.jar' => 'bytes'];
        Http::fake();

        $result = $this->scan();

        $this->assertSame(['bad..name.jar'], $result['skipped']);
        $this->assertSame([], $this->reads);
        Http::assertNothingSent();
    }

    public function test_a_failed_lookup_imports_nothing_and_keeps_the_previous_result(): void
    {
        $this->jars = ['homemade.jar' => 'homemade bytes'];
        UnmatchedJars::remember(1, ModrinthProjectType::Mod, ['homemade.jar' => strlen('homemade bytes')]);
        Http::fake(['api.modrinth.com/v2/version_files' => Http::response('down', 503)]);

        $result = $this->scan();

        $this->assertTrue($result['error']);
        $this->assertSame([], $result['imported']);
        $this->assertSame([], $result['not_on_modrinth']);
        $this->assertSame(0, $this->metadataWrites);
        // An outage says nothing about whether a jar is on Modrinth.
        $this->assertSame(['homemade.jar' => strlen('homemade bytes')], UnmatchedJars::remembered(1, ModrinthProjectType::Mod));
    }

    public function test_a_failed_metadata_write_reports_an_error_and_imports_nothing(): void
    {
        $this->jars = ['sodium-0.6.0.jar' => 'sodium bytes'];
        $this->fakeModrinth(['sodium-0.6.0.jar' => $this->version('sod6', 'AANobbMI', '0.6.0', 'sodium-0.6.0.jar')]);

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getDirectory')->andReturnUsing(fn () => $this->listing());
        $repository->shouldReceive('getContent')->andReturnUsing(fn (string $path) => $path === 'mods/.modrinth-metadata.json' ? $this->metadata : $this->jars[basename($path)]);
        $repository->shouldReceive('putContent')->andReturn(new Response(new Psr7Response(500)));
        $repository->shouldReceive('deleteFiles')->never();
        $repository->shouldReceive('renameFiles')->never();
        $this->app->instance(DaemonFileRepository::class, $repository);

        $result = $this->scan();

        $this->assertTrue($result['error']);
        $this->assertSame([], $result['imported']);
    }

    public function test_untracked_lists_only_jar_files_missing_from_the_metadata(): void
    {
        $listing = [
            ['name' => 'a.jar', 'size' => 3, 'file' => true, 'directory' => false],
            ['name' => 'B.JAR', 'size' => 4, 'file' => true, 'directory' => false],
            ['name' => 'tracked.jar', 'size' => 5, 'file' => true, 'directory' => false],
            ['name' => 'folder.jar', 'size' => 0, 'file' => false, 'directory' => true],
            ['name' => 'notes.txt', 'size' => 1, 'file' => true, 'directory' => false],
        ];

        $untracked = UnmatchedJars::untracked($listing, [['filename' => 'Tracked.jar']]);

        $this->assertSame(['a.jar', 'B.JAR'], array_column($untracked, 'name'));
    }
}
