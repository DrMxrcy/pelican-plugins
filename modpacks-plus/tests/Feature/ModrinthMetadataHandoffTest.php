<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\Steps\FinalizeStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use DrMxrcy\ModpacksPlus\Tests\Support\FailingReadServerFiles;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Plan 33 step 2: the finalize step hands the installed pack's Modrinth mods over to
 * Modrinth Plus through `mods/.modrinth-metadata.json`, and the install summary says what
 * happened. A metadata file that can't be read or parsed is never touched, and the install
 * itself still succeeds.
 */
class ModrinthMetadataHandoffTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const PATH = ModrinthMetadataWriter::PATH;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    /**
     * The scenario pack, with Modrinth answering the hash lookup with project ids (as the
     * real API does) and the projects endpoint with titles.
     *
     * @param  array<string, string>|null  $extraEntries
     */
    private function pack(?array $extraEntries = null): void
    {
        $archive = $this->scenarioPack($extraEntries);

        $lookup = Mrpack::lookup([$this->pack['sodium'], $this->pack['lithium']]);
        $lookup[$this->pack['sodium']['hashes']['sha512']] += ['project_id' => 'AANobbMI', 'version_number' => 'mc1.20.1-0.5.8'];
        $lookup[$this->pack['lithium']['hashes']['sha512']] += ['project_id' => 'gvQqBUqZ', 'version_number' => 'mc1.20.1-0.11.2'];

        $stubs = [
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => Http::response($lookup),
            'api.modrinth.com/v2/projects*' => Http::response([
                ['id' => 'AANobbMI', 'slug' => 'sodium', 'title' => 'Sodium'],
                ['id' => 'gvQqBUqZ', 'slug' => 'lithium', 'title' => 'Lithium'],
            ]),
            Mrpack::ARCHIVE_URL => Http::response($archive),
        ];
        foreach ($this->pack as $file) {
            $stubs[$file['downloads'][0]] = Http::response($this->files->remote[$file['downloads'][0]]);
        }

        Http::swap(new HttpFactory());
        Http::fake($stubs + ['*' => Http::response('unexpected', 500)]);
    }

    /**
     * @param  string|null  $metadataAtFinalize  when given, this metadata file appears in mods/
     *                                           right before finalize (e.g. Modrinth Plus wrote it
     *                                           meanwhile); a pack can't ship one itself
     */
    private function install(Server $server, ?string $metadataAtFinalize = null): PackInstall
    {
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
        $this->runJobs(between: function ($job) use ($server, $metadataAtFinalize) {
            $this->finishBackups($server);
            if ($metadataAtFinalize !== null && $job instanceof FinalizeStep) {
                $this->files->seed([self::PATH => $metadataAtFinalize]);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));

        return $install;
    }

    public function test_the_packs_modrinth_mods_are_recorded_for_modrinth_plus(): void
    {
        [, $server] = $this->scenarioServer();
        $this->pack();
        $previous = json_encode(['installed_mods' => [['project_id' => 'OLD', 'filename' => 'old.jar', 'pinned' => true]], 'x' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->files->seed(['mods/old.jar' => 'old', self::PATH => (string) $previous]);

        $install = $this->install($server);

        $document = json_decode($this->files->files[self::PATH] ?? 'null', true);
        $this->assertIsArray($document);
        $tag = ['provider' => 'modrinth', 'pack_id' => Mrpack::PACK_ID, 'version_id' => Mrpack::VERSION_ID, 'pack_name' => 'Test Pack', 'version_number' => '1.0.0'];
        $this->assertSame([
            ['AANobbMI', 'sodium', 'Sodium', 'mc1.20.1-0.5.8', 'sodium.jar', $tag],
            ['gvQqBUqZ', 'lithium', 'Lithium', 'mc1.20.1-0.11.2', 'lithium.jar', $tag],
        ], array_map(fn (array $entry) => [$entry['project_id'], $entry['project_slug'], $entry['project_title'], $entry['version_number'], $entry['filename'], $entry['installed_as_modpack']], $document['installed_mods']));
        // GitHub-hosted and client-only jars are not on Modrinth / not installed: not recorded.
        $this->assertNotContains('from-github.jar', array_column($document['installed_mods'], 'filename'));

        // The previous pack's metadata moved aside with its mods folder, byte for byte.
        $this->assertSame($previous, $this->files->files[$install->moved_aside_path.'/'.self::PATH] ?? null);
        $this->assertSame([], $this->files->violations);

        $this->assertSame('written', $install->summary['modrinth_metadata']['status'] ?? null);
        $this->assertSame(2, $install->summary['modrinth_metadata']['added'] ?? null);
        $html = InstallProgress::summary($install)->toHtml();
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata.written', ['added' => 2, 'updated' => 0])), $html);
        // Ruling 8: the previous Modrinth Plus list and pins stay in the moved-aside folder, and the summary says where.
        $this->assertSame($install->moved_aside_path.'/'.self::PATH, $install->summary['modrinth_metadata']['previous'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata_previous', ['path' => $install->moved_aside_path.'/'.self::PATH])), $html);
    }

    public function test_a_pack_cannot_ship_its_own_metadata_file(): void
    {
        [, $server] = $this->scenarioServer();
        $forged = '{"installed_mods": [{"project_id": "FAKE", "project_slug": "fake", "project_title": "Fake", "version_id": "x", "version_number": "1", "filename": "sodium.jar", "installed_at": "2026-01-01T00:00:00+00:00", "pinned": true}]}';
        $this->pack([
            'overrides/mods/.modrinth-metadata.json' => $forged,
            'server-overrides/plugins/.Modrinth-Metadata.json' => $forged,
        ]);

        $install = $this->install($server);

        // Fails if: the pack's file is written, so Modrinth Plus would trust its fake entries.
        // Written once: by the hand-off, never by the overrides step.
        $this->assertCount(1, array_keys($this->files->callsOf('write'), self::PATH, true));
        $this->assertNotContains('plugins/.Modrinth-Metadata.json', $this->files->callsOf('write'));
        $this->assertArrayNotHasKey('plugins/.Modrinth-Metadata.json', $this->files->files);
        $this->assertSame(['AANobbMI', 'gvQqBUqZ'], array_column(json_decode($this->files->files[self::PATH], true)['installed_mods'], 'project_id'));
        $this->assertEqualsCanonicalizing(['mods/.modrinth-metadata.json', 'plugins/.Modrinth-Metadata.json'], $install->summary['skipped_overrides'] ?? []);
    }

    public function test_an_unreadable_metadata_file_is_left_untouched_and_reported(): void
    {
        $this->app->instance(ServerFiles::class, new FailingReadServerFiles($this->files, self::PATH));
        [, $server] = $this->scenarioServer();
        $this->pack();

        // A metadata file exists by finalize; the daemon then fails to read it.
        $install = $this->install($server, '{"installed_mods": [], "from": "modrinth plus"}');

        // Fails if: a failed read is taken as "no file" and the file is replaced.
        $this->assertSame('{"installed_mods": [], "from": "modrinth plus"}', $this->files->files[self::PATH] ?? null);
        $this->assertNotContains(self::PATH, $this->files->callsOf('write'));
        $this->assertSame('unreadable', $install->summary['modrinth_metadata']['status'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata.unreadable', ['path' => self::PATH])), InstallProgress::summary($install)->toHtml());
    }

    public function test_an_unparsable_metadata_file_is_left_untouched_and_reported(): void
    {
        [, $server] = $this->scenarioServer();
        $this->pack();

        $install = $this->install($server, '{"installed_mods": [ broken');

        $this->assertSame('{"installed_mods": [ broken', $this->files->files[self::PATH] ?? null);
        $this->assertSame('invalid', $install->summary['modrinth_metadata']['status'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata.invalid', ['path' => self::PATH])), InstallProgress::summary($install)->toHtml());
    }

    public function test_a_modrinth_outage_at_the_end_does_not_fail_the_install(): void
    {
        [, $server] = $this->scenarioServer();
        $this->pack();

        // Modrinth goes down after the downloads: only the hand-off is skipped.
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
        $this->runJobs(between: function ($job) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof FinalizeStep) {
                Http::swap(new HttpFactory());
                Http::fake(['*' => Http::response('down', 503)]);
            }
        });
        $install = $this->latestInstall();

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame('lookup_failed', $install->summary['modrinth_metadata']['status'] ?? null);
        $this->assertArrayNotHasKey(self::PATH, $this->files->files);
    }
}
