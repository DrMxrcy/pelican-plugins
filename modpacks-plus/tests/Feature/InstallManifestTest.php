<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Jobs\Steps\FinalizeStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Plan 36 step 1: the installed-files manifest written at finalize (path, size, hash), which
 * updates diff against.
 */
class InstallManifestTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    private function install(Server $server, ?\Closure $between = null): PackInstall
    {
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
        $this->runJobs(between: function (InstallStep $job, PackInstall $install) use ($server, $between) {
            $this->finishBackups($server);
            if ($between !== null) {
                $between($job, $install);
            }
        });

        return $this->latestInstall();
    }

    public function test_finalize_records_every_installed_file_with_its_size_and_hash(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->files->seed(['server.properties' => "level-name=world\n", 'world/level.dat' => 'level']);

        $install = $this->install($server);
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));

        $manifest = $install->manifest;
        $this->assertIsArray($manifest);
        $this->assertSame([
            ['path' => 'config/extra/deep.json', 'size' => 2, 'hash' => 'sha512:'.hash('sha512', '{}'), 'kept' => false],
            // server-overrides/ replaced overrides/: the manifest has what was written.
            ['path' => 'config/sodium.toml', 'size' => 6, 'hash' => 'sha512:'.hash('sha512', 'server'), 'kept' => false],
            ['path' => 'mods/from-github.jar', 'size' => 12, 'hash' => 'sha512:'.hash('sha512', 'github-bytes'), 'kept' => false],
            ['path' => 'mods/lithium.jar', 'size' => 13, 'hash' => 'sha512:'.hash('sha512', 'lithium-bytes'), 'kept' => false],
            ['path' => 'mods/sodium.jar', 'size' => 12, 'hash' => 'sha512:'.hash('sha512', 'sodium-bytes'), 'kept' => false],
        ], $manifest);

        // Every recorded file is on the server with exactly that content.
        foreach ($manifest as $entry) {
            $this->assertSame(substr($entry['hash'], 7), hash('sha512', $this->files->files[$entry['path']]), $entry['path']);
        }

        // Never recorded: protected targets (ops.json, the world), client-only files and overrides.
        $paths = array_column($manifest, 'path');
        foreach (['ops.json', 'world/datapacks/x.zip', 'mods/zoomify.jar', 'options.txt'] as $path) {
            $this->assertNotContains($path, $paths);
        }
        $this->assertNotNull($install->manifestEntries());
        $this->assertCount(5, $install->manifestEntries());
        $this->assertSame(1, collect($install->log)->where('key', 'manifest_recorded')->count());
    }

    public function test_a_pack_without_overrides_records_its_downloads(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack([]);

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame(['mods/from-github.jar', 'mods/lithium.jar', 'mods/sodium.jar'], array_column($install->manifest ?? [], 'path'));
    }

    public function test_finalize_refetches_a_missing_archive_and_records_the_overrides(): void
    {
        [, $server] = $this->scenarioServer();
        $archive = $this->scenarioPack();

        $install = $this->install($server, function (InstallStep $job, PackInstall $install) use ($archive) {
            if ($job instanceof FinalizeStep) {
                File::deleteDirectory(app(InstallEngine::class)->workDir($install));
                // A fresh answer per request (a faked response body is read only once).
                Http::swap(new HttpFactory());
                Http::fake([
                    Mrpack::ARCHIVE_URL => fn () => Http::response($archive),
                    'api.modrinth.com/v2/version_files' => fn () => Http::response(Mrpack::lookup([$this->pack['sodium'], $this->pack['lithium']])),
                ]);
            }
        });

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertContains('config/sodium.toml', array_column($install->manifest ?? [], 'path'), (string) json_encode($install->log));
    }

    public function test_an_unbuildable_manifest_never_fails_the_install(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        // At finalize the panel copy of the archive is gone and the source answers with garbage.
        $install = $this->install($server, function (InstallStep $job, PackInstall $install) {
            if ($job instanceof FinalizeStep) {
                File::deleteDirectory(app(InstallEngine::class)->workDir($install));
                Http::swap(new HttpFactory());
                Http::fake([Mrpack::ARCHIVE_URL => Http::response('not the archive')]);
            }
        });

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertNull($install->manifest);
        $this->assertNull($install->manifestEntries());
        $this->assertSame(1, collect($install->log)->where('key', 'manifest_failed')->count());
        // The pack itself is in place.
        $this->assertSame('server', $this->files->files['config/sodium.toml'] ?? null);
    }

    public function test_a_tampered_stored_manifest_is_rechecked_on_read(): void
    {
        [, $server] = $this->scenarioServer();
        $install = PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => 'modrinth', 'pack_id' => Mrpack::PACK_ID, 'version_id' => Mrpack::VERSION_ID,
            'name' => 'x', 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 1,
            'manifest' => [
                ['path' => 'mods/ok.jar', 'size' => 3, 'hash' => 'sha1:'.sha1('abc')],
                ['path' => '../escape.jar', 'size' => 3, 'hash' => 'sha1:'.sha1('abc')],
                ['path' => 'mods/bad-hash.jar', 'size' => 3, 'hash' => 'md5:'.md5('abc')],
                ['path' => 'mods/short.jar', 'size' => 3, 'hash' => 'sha512:abc'],
                ['path' => 'mods/negative.jar', 'size' => -1, 'hash' => 'sha1:'.sha1('abc')],
                'not an entry',
            ],
        ]);

        $this->assertSame(['mods/ok.jar'], array_keys($install->fresh()->manifestEntries() ?? []));
        $this->assertSame(PackInstall::MODE_INSTALL, $install->fresh()->mode);
    }
}
