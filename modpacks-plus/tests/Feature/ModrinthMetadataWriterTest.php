<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use DrMxrcy\ModpacksPlus\Tests\Support\FailingReadServerFiles;
use DrMxrcy\ModpacksPlus\Tests\Support\FakeServerFiles;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 33 step 1: after a pack install, the pack's mods that are on Modrinth (matched by
 * hash through /v2/version_files) are recorded in `mods/.modrinth-metadata.json` in Modrinth
 * Plus's entry shape, plus `installed_as_modpack`. The file is only appended to or merged
 * into, under Modrinth Plus's metadata lock, and never written when it can't be read.
 */
class ModrinthMetadataWriterTest extends TestCase
{
    private const PATH = 'mods/.modrinth-metadata.json';

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    private const NOW = '2026-11-09T12:00:00+00:00';

    private FakeServerFiles $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::NOW);
        $this->files = new FakeServerFiles();
        $this->files->seed([
            'mods/sodium.jar' => 'sodium-bytes',
            'mods/lithium.jar' => 'lithium-bytes',
            'mods/unknown.jar' => 'unknown-bytes',
            'config/sodium.jar' => 'not a mod folder',
        ]);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 7;

        return $server;
    }

    private static function file(string $path, string $contents, ?int $size = null): PlanFile
    {
        return new PlanFile(
            path: $path,
            urls: ['https://cdn.modrinth.com/data/x/versions/y/'.basename($path)],
            size: $size ?? strlen($contents),
            hashes: ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)],
            url: 'https://cdn.modrinth.com/data/x/versions/y/'.basename($path),
            verify: PlanFile::VERIFY_MODRINTH,
        );
    }

    /**
     * @param  array<int, PlanFile>|null  $files
     * @param  array<int, OverrideEntry>  $overrides
     */
    private function install(?array $files = null, array $overrides = []): PackInstall
    {
        $plan = new InstallPlan(
            provider: 'modrinth',
            packId: 'PACKID01',
            versionId: 'VERSID02',
            packName: 'Cool Pack 2.0.0',
            versionNumber: '2.0.0',
            minecraftVersion: '1.20.1',
            loader: 'fabric',
            loaderVersion: '0.15.11',
            files: $files ?? [
                self::file('mods/sodium.jar', 'sodium-bytes'),
                self::file('mods/lithium.jar', 'lithium-bytes'),
                self::file('mods/unknown.jar', 'unknown-bytes'),
                self::file('config/sodium.jar', 'not a mod folder'),
            ],
            overrides: $overrides,
        );

        $install = new PackInstall();
        $install->forceFill([
            'provider' => 'modrinth',
            'pack_id' => 'PACKID01',
            'version_id' => 'VERSID02',
            'name' => 'Cool Pack',
            'version_number' => '2.0.0',
        ]);
        $install->plan = $plan->toArray();

        return $install;
    }

    /** @return array<string, mixed> a version_files answer for $contents */
    private static function version(string $contents, string $projectId, string $versionId, string $number): array
    {
        return [
            'id' => $versionId,
            'project_id' => $projectId,
            'version_number' => $number,
            'files' => [['url' => "https://cdn.modrinth.com/data/$projectId/versions/$versionId/x.jar", 'filename' => 'x.jar', 'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)]]],
        ];
    }

    /** Fakes Modrinth: sodium and lithium are known by hash, unknown.jar is not. */
    private function fakeModrinth(int $lookupStatus = 200, int $projectsStatus = 200): void
    {
        Http::fake([
            'api.modrinth.com/v2/version_files' => $lookupStatus === 200
                ? Http::response([
                    hash('sha512', 'sodium-bytes') => self::version('sodium-bytes', 'AANobbMI', 'sodV2', '0.5.8'),
                    hash('sha512', 'lithium-bytes') => self::version('lithium-bytes', 'gvQqBUqZ', 'litV2', '0.11.2'),
                ])
                : Http::response('down', $lookupStatus),
            'api.modrinth.com/v2/projects*' => $projectsStatus === 200
                ? Http::response([
                    ['id' => 'AANobbMI', 'slug' => 'sodium', 'title' => 'Sodium'],
                    ['id' => 'gvQqBUqZ', 'slug' => 'lithium', 'title' => 'Lithium'],
                ])
                : Http::response('down', $projectsStatus),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    /** @return array<string, mixed> */
    private function record(?PackInstall $install = null, int $lockWait = 1): array
    {
        return (new ModrinthMetadataWriter(app(ModrinthClient::class), $lockWait))->record($this->server(), $this->files, $install ?? $this->install());
    }

    /** @return array<string, mixed> */
    private static function packTag(): array
    {
        return ['provider' => 'modrinth', 'pack_id' => 'PACKID01', 'version_id' => 'VERSID02', 'pack_name' => 'Cool Pack', 'version_number' => '2.0.0'];
    }

    /** @return array<string, mixed> */
    private static function newEntry(string $id, string $slug, string $title, string $versionId, string $number, string $filename): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $slug,
            'project_title' => $title,
            'version_id' => $versionId,
            'version_number' => $number,
            'filename' => $filename,
            'installed_at' => self::NOW,
            'installed_as_modpack' => self::packTag(),
        ];
    }

    public function test_creates_the_file_with_an_entry_per_matched_mod_in_modrinth_plus_shape(): void
    {
        $this->fakeModrinth();

        $result = $this->record();

        $this->assertSame('written', $result['status']);
        $this->assertSame(2, $result['added']);
        // Fails if: the shape, key order or JSON flags differ from what Modrinth Plus writes.
        $this->assertSame(json_encode(['installed_mods' => [
            self::newEntry('AANobbMI', 'sodium', 'Sodium', 'sodV2', '0.5.8', 'sodium.jar'),
            self::newEntry('gvQqBUqZ', 'lithium', 'Lithium', 'litV2', '0.11.2', 'lithium.jar'),
        ]], self::FLAGS), $this->files->files[self::PATH]);
        $this->assertSame([self::PATH], $this->files->callsOf('write'));
        $this->assertSame([], $this->files->violations);

        // Only mods/*.jar were looked up, by sha512, with the plugin's User-Agent.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/version_files')
            && $request['algorithm'] === 'sha512'
            && $request['hashes'] === [hash('sha512', 'sodium-bytes'), hash('sha512', 'lithium-bytes'), hash('sha512', 'unknown-bytes')]
            && str_starts_with($request->header('User-Agent')[0] ?? '', 'DrMxrcy/modpacks-plus/'));
    }

    public function test_merges_into_an_existing_file_keeping_unknown_keys_order_and_other_entries(): void
    {
        $this->fakeModrinth();
        $userEntry = ['project_id' => 'P7xyz', 'project_slug' => 'jei', 'project_title' => 'JEI', 'version_id' => 'j1', 'version_number' => '1', 'filename' => 'jei.jar', 'installed_at' => '2026-01-01T00:00:00+00:00', 'pinned' => true, 'note' => ['nested' => new \stdClass()]];
        $oldPackEntry = ['project_id' => 'AANobbMI', 'project_slug' => 'sodium', 'project_title' => 'Sodium', 'version_id' => 'sodV1', 'version_number' => '0.5.0', 'filename' => 'sodium-old.jar', 'installed_at' => '2026-01-01T00:00:00+00:00', 'pinned' => true, 'author' => 'jellysquid', 'installed_as_modpack' => ['provider' => 'modrinth', 'pack_id' => 'PACKID01', 'version_id' => 'VERSID01', 'pack_name' => 'Cool Pack', 'version_number' => '1.0.0']];
        $partial = ['project_id' => 'partial', 'filename' => 'partial.jar'];
        $this->files->seed([self::PATH => json_encode(['written_by' => 'minecraft-modrinth', 'installed_mods' => [$userEntry, $oldPackEntry, $partial, 'junk'], 'extra' => new \stdClass()], self::FLAGS)]);

        $result = $this->record();

        $this->assertSame('written', $result['status']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['updated']);

        $merged = $oldPackEntry;
        $merged['version_id'] = 'sodV2';
        $merged['version_number'] = '0.5.8';
        $merged['filename'] = 'sodium.jar';
        $merged['installed_as_modpack'] = self::packTag();
        $merged['updated_at'] = self::NOW;

        // Fails if: an existing entry or key is lost, reordered, or `{}` becomes `[]`.
        $this->assertSame(json_encode(['written_by' => 'minecraft-modrinth', 'installed_mods' => [
            $userEntry,
            $merged,
            $partial,
            'junk',
            self::newEntry('gvQqBUqZ', 'lithium', 'Lithium', 'litV2', '0.11.2', 'lithium.jar'),
        ], 'extra' => new \stdClass()], self::FLAGS), $this->files->files[self::PATH]);
    }

    public function test_never_takes_over_a_users_own_entry_or_another_projects_file_name(): void
    {
        $this->fakeModrinth();
        // The user tracks sodium under another jar; another project claims lithium.jar.
        $userSodium = ['project_id' => 'AANobbMI', 'project_slug' => 'sodium', 'project_title' => 'Sodium', 'version_id' => 's0', 'version_number' => '0.4', 'filename' => 'sodium-mine.jar', 'installed_at' => '2026-01-01T00:00:00+00:00'];
        $other = ['project_id' => 'OTHER', 'project_slug' => 'other', 'project_title' => 'Other', 'version_id' => 'o1', 'version_number' => '1', 'filename' => 'Lithium.jar', 'installed_at' => '2026-01-01T00:00:00+00:00'];
        $original = json_encode(['installed_mods' => [$userSodium, $other]], self::FLAGS);
        $this->files->seed([self::PATH => $original]);

        $result = $this->record();

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(['sodium.jar'], $result['kept']);
        $this->assertSame(['lithium.jar'], $result['conflicts']);
        $this->assertSame($original, $this->files->files[self::PATH]);
        $this->assertSame([], $this->files->callsOf('write'));
    }

    public function test_an_unreadable_file_is_never_written(): void
    {
        $this->fakeModrinth();
        $files = new FailingReadServerFiles($this->files, self::PATH);
        $this->files->seed([self::PATH => '{"installed_mods": []}']);

        $result = (new ModrinthMetadataWriter(app(ModrinthClient::class), 1))->record($this->server(), $files, $this->install());

        // Fails if: a daemon read error is treated as "no file yet" and the file is replaced.
        $this->assertSame('unreadable', $result['status']);
        $this->assertSame('{"installed_mods": []}', $this->files->files[self::PATH]);
        $this->assertSame([], $this->files->callsOf('write'));
    }

    /** @return array<string, array{string}> */
    public static function invalidDocuments(): array
    {
        return [
            'not json' => ['{"installed_mods": [ broken'],
            'a list' => ['[1, 2]'],
            'a scalar' => ['"text"'],
            'installed_mods not a list' => ['{"installed_mods": {"a": 1}}'],
            'installed_mods a string' => ['{"installed_mods": "x"}'],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_an_unparsable_file_is_left_untouched(string $contents): void
    {
        $this->fakeModrinth();
        $this->files->seed([self::PATH => $contents]);

        $result = $this->record();

        $this->assertSame('invalid', $result['status']);
        $this->assertSame($contents, $this->files->files[self::PATH]);
        $this->assertSame([], $this->files->callsOf('write'));
    }

    public function test_an_empty_file_is_an_empty_document(): void
    {
        $this->fakeModrinth();
        $this->files->seed([self::PATH => "  \n"]);

        $this->assertSame('written', $this->record()['status']);
        $this->assertCount(2, json_decode($this->files->files[self::PATH], true)['installed_mods']);
    }

    public function test_a_failed_lookup_writes_nothing(): void
    {
        $this->fakeModrinth(lookupStatus: 503);

        $result = $this->record();

        $this->assertSame('lookup_failed', $result['status']);
        $this->assertArrayNotHasKey(self::PATH, $this->files->files);
        $this->assertSame([], $this->files->callsOf('read'));
    }

    public function test_a_failed_project_lookup_writes_nothing(): void
    {
        // Ruling (plan 33 review): no id-as-slug / jar-as-title fallback that would stick forever.
        $this->fakeModrinth(projectsStatus: 503);

        $this->assertSame('lookup_failed', $this->record()['status']);
        $this->assertArrayNotHasKey(self::PATH, $this->files->files);
        $this->assertSame([], $this->files->callsOf('read'));
    }

    public function test_a_project_missing_from_the_project_lookup_is_not_recorded(): void
    {
        Http::fake([
            'api.modrinth.com/v2/version_files' => Http::response([
                hash('sha512', 'sodium-bytes') => self::version('sodium-bytes', 'AANobbMI', 'sodV2', '0.5.8'),
                hash('sha512', 'lithium-bytes') => self::version('lithium-bytes', 'gvQqBUqZ', 'litV2', '0.11.2'),
            ]),
            'api.modrinth.com/v2/projects*' => Http::response([['id' => 'AANobbMI', 'slug' => 'sodium', 'title' => 'Sodium']]),
            '*' => Http::response('unexpected', 500),
        ]);

        $result = $this->record();

        $this->assertSame(1, $result['added']);
        $this->assertSame(['lithium.jar'], $result['skipped']);
        $this->assertSame(['sodium.jar'], array_column(json_decode($this->files->files[self::PATH], true)['installed_mods'], 'filename'));
    }

    public function test_a_plan_that_downloads_the_metadata_file_itself_is_refused(): void
    {
        $this->fakeModrinth();
        $this->files->seed([self::PATH => '{"installed_mods": []}']);

        // Defence in depth behind PlanValidator's pack_ships_metadata.
        $result = $this->record($this->install([
            self::file('mods/sodium.jar', 'sodium-bytes'),
            self::file('MODS/.Modrinth-Metadata.json', '{"installed_mods": []}'),
        ]));

        $this->assertSame('invalid', $result['status']);
        $this->assertSame([], $this->files->callsOf('read'));
        $this->assertSame([], $this->files->callsOf('write'));
        Http::assertNothingSent();
    }

    public function test_a_directory_at_the_metadata_path_is_unreadable(): void
    {
        $this->fakeModrinth();
        $this->files->seed([self::PATH.'/' => '']);

        $result = $this->record();

        $this->assertSame('unreadable', $result['status']);
        $this->assertSame([], $this->files->callsOf('read'));
        $this->assertSame([], $this->files->callsOf('write'));
    }

    public function test_an_oversized_file_is_unreadable_and_never_downloaded(): void
    {
        $this->fakeModrinth();
        $big = str_repeat(' ', ModrinthMetadataWriter::MAX_BYTES + 1);
        $this->files->seed([self::PATH => $big]);

        $result = $this->record();

        // Fails if: the whole file is pulled through the daemon before its size is checked.
        $this->assertSame('unreadable', $result['status']);
        $this->assertSame([], $this->files->callsOf('read'));
        $this->assertSame([], $this->files->callsOf('write'));
        $this->assertSame($big, $this->files->files[self::PATH]);
    }

    public function test_a_jar_an_override_also_writes_is_not_recorded(): void
    {
        $this->fakeModrinth();

        // The index claims sodium's hash, but the overrides ship other bytes under that name.
        $result = $this->record($this->install(overrides: [new OverrideEntry('overrides/mods/sodium.jar', 'mods/sodium.jar', 12)]));

        $this->assertSame(1, $result['added']);
        $this->assertSame(['lithium.jar'], array_column(json_decode($this->files->files[self::PATH], true)['installed_mods'], 'filename'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/version_files')
            && !in_array(hash('sha512', 'sodium-bytes'), $request['hashes'], true));
    }

    public function test_waits_for_modrinth_plus_metadata_lock_and_gives_up_without_writing(): void
    {
        $this->fakeModrinth();
        // The exact lock Modrinth Plus takes around every metadata write.
        $held = Cache::lock('modrinth_metadata:7', 10);
        $this->assertTrue($held->get());

        try {
            $result = $this->record(lockWait: 0);
        } finally {
            $held->release();
        }

        $this->assertSame('busy', $result['status']);
        $this->assertSame([], $this->files->callsOf('read'));
        $this->assertArrayNotHasKey(self::PATH, $this->files->files);
    }

    public function test_running_again_changes_nothing(): void
    {
        $this->fakeModrinth();
        $this->record();
        $first = $this->files->files[self::PATH];

        $this->travel(5)->minutes();
        $result = $this->record();

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(2, $result['already']);
        $this->assertSame($first, $this->files->files[self::PATH]);
        $this->assertCount(1, $this->files->callsOf('write'));
    }

    public function test_files_missing_on_disk_or_outside_mods_are_not_recorded(): void
    {
        $this->fakeModrinth();
        unset($this->files->files['mods/lithium.jar']);

        $result = $this->record($this->install([
            self::file('mods/sodium.jar', 'sodium-bytes', 999),
            self::file('mods/lithium.jar', 'lithium-bytes'),
            self::file('mods/sub/deep.jar', 'sodium-bytes'),
            self::file('config/sodium.jar', 'sodium-bytes'),
        ]));

        $this->assertSame('nothing', $result['status']);
        $this->assertSame([], $this->files->callsOf('write'));
        Http::assertNothingSent();
    }

    public function test_a_failed_write_is_reported(): void
    {
        $this->fakeModrinth();
        $files = new FailingReadServerFiles($this->files, failWrites: true);

        $result = (new ModrinthMetadataWriter(app(ModrinthClient::class), 1))->record($this->server(), $files, $this->install());

        $this->assertSame('write_failed', $result['status']);
    }

    public function test_two_jars_of_one_project_record_only_the_first(): void
    {
        $this->fakeModrinth();
        $this->files->seed(['mods/sodium-copy.jar' => 'sodium-bytes']);

        $result = $this->record($this->install([
            self::file('mods/sodium.jar', 'sodium-bytes'),
            self::file('mods/sodium-copy.jar', 'sodium-bytes'),
        ]));

        $this->assertSame(1, $result['added']);
        $this->assertSame(['sodium-copy.jar'], $result['conflicts']);
        $entries = json_decode($this->files->files[self::PATH], true)['installed_mods'];
        $this->assertSame(['sodium.jar'], array_column($entries, 'filename'));
    }

    public function test_modpacks_plus_never_depends_on_modrinth_plus(): void
    {
        // Fails if: this plugin needs Modrinth Plus installed; the shared file is the only link.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertStringNotContainsString('ModrinthPlus', (string) file_get_contents((string) $file), (string) $file);
        }
        $this->assertStringNotContainsString('modrinth-plus', (string) file_get_contents(dirname(__DIR__, 2).'/plugin.json'));
    }
}
