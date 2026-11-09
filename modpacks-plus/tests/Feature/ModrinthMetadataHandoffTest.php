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

    private function install(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));

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
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata.written', ['added' => 2, 'updated' => 0])), InstallProgress::summary($install)->toHtml());
    }

    public function test_an_unreadable_metadata_file_is_left_untouched_and_reported(): void
    {
        $this->app->instance(ServerFiles::class, new FailingReadServerFiles($this->files, self::PATH));
        [, $server] = $this->scenarioServer();
        // The pack itself ships a metadata file; the daemon then fails to read it back.
        $this->pack(['overrides/mods/.modrinth-metadata.json' => '{"installed_mods": [], "from": "pack"}']);

        $install = $this->install($server);

        // Fails if: a failed read is taken as "no file" and the file is replaced.
        $this->assertSame('{"installed_mods": [], "from": "pack"}', $this->files->files[self::PATH] ?? null);
        // Written once, by the overrides step; never again by the hand-off.
        $this->assertCount(1, array_keys($this->files->callsOf('write'), self::PATH, true));
        $this->assertSame('unreadable', $install->summary['modrinth_metadata']['status'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.metadata.unreadable', ['path' => self::PATH])), InstallProgress::summary($install)->toHtml());
    }

    public function test_an_unparsable_metadata_file_is_left_untouched_and_reported(): void
    {
        [, $server] = $this->scenarioServer();
        $this->pack(['overrides/mods/.modrinth-metadata.json' => '{"installed_mods": [ broken']);

        $install = $this->install($server);

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
