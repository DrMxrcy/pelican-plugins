<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 32: an FTB pack installs through the item-30 engine. FTB's own files are fetched
 * and sha1-checked by the panel (never pulled by the node), client-only files are left out,
 * and CurseForge files with no key set up are listed as "needs CurseForge key" while the
 * rest of the pack installs.
 */
class FtbInstallTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', false);
    }

    private function forgeServer(): Server
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed([
            'mods/old.jar' => 'old',
            'world/level.dat' => 'level',
            'ops.json' => '[]',
            'server.properties' => "level-name=world\n",
        ]);

        return $server;
    }

    /**
     * @param  array<string, string>  $bodies  URL => body the host serves instead of the file
     * @param  array<string, mixed>  $extra
     */
    private function fakePack(array $bodies = [], array $extra = []): void
    {
        $files = [
            Ftb::file('./mods', 'ftb-library.jar', 'ftb-library-bytes', 'mod'),
            Ftb::file('./config', 'ftb.toml', 'x=1'),
            Ftb::file('./mods', 'minimap.jar', 'minimap-bytes', 'mod', ['clientonly' => true]),
            Ftb::curseForgeMod('cf-only.jar', 'cf-bytes', 301, 6000001),
            Ftb::file('.', 'ops.json', '[{"name":"attacker"}]'),
        ];

        $stubs = [
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version($files)),
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
            'api.modrinth.com/v2/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ];
        foreach ([['./mods/ftb-library.jar', 'ftb-library-bytes'], ['./config/ftb.toml', 'x=1']] as [$path, $contents]) {
            $url = Ftb::distUrl($path, $contents);
            // dist.modpacks.ch sends no Content-Length.
            $stubs[$url] = Http::response($bodies[$url] ?? $contents);
        }

        Http::swap(new HttpFactory());
        Http::fake($extra + $stubs + ['*' => Http::response('unexpected', 500)]);
    }

    private function install(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, 'FTB Test Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));

        return $this->latestInstall();
    }

    public function test_installs_an_ftb_pack_and_lists_curseforge_files_needing_a_key(): void
    {
        $server = $this->forgeServer();
        $this->fakePack();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('ftb-library-bytes', $this->files->files['mods/ftb-library.jar'] ?? null);
        $this->assertSame('x=1', $this->files->files['config/ftb.toml'] ?? null);
        $this->assertArrayNotHasKey('mods/minimap.jar', $this->files->files);
        $this->assertArrayNotHasKey('mods/cf-only.jar', $this->files->files);
        $this->assertSame('[]', $this->files->files['ops.json']);
        $this->assertSame([], $this->files->violations);

        // The node never pulls an FTB or CurseForge file; CurseForge was never asked.
        $this->assertSame([], $this->files->callsOf('pull'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curseforge.com') || str_contains($request->url(), 'forgecdn.net'));

        $summary = $install->summary ?? [];
        $this->assertSame([['name' => 'cf-only.jar', 'url' => null, 'reason' => 'curseforge_key_required']], $summary['manual_files']);
        $this->assertSame(1, $summary['skipped_client_files']);
        $this->assertSame(['ops.json'], $summary['skipped_overrides']);
        $this->assertStringContainsString('needs CurseForge key', (string) InstallProgress::summary($install, true));
    }

    public function test_an_ftb_file_with_the_wrong_bytes_is_never_written(): void
    {
        $server = $this->forgeServer();
        $this->fakePack([Ftb::distUrl('./mods/ftb-library.jar', 'ftb-library-bytes') => 'ftb-librarX-bytes']);

        $install = $this->install($server);

        $this->assertSame('hash_mismatch', $install->error);
        $this->assertArrayNotHasKey('mods/ftb-library.jar', $this->files->files);
        $this->assertSame([], array_values(array_filter($this->files->callsOf('write'), fn (string $path) => str_ends_with($path, 'ftb-library.jar'))));
    }

    public function test_a_curseforge_file_with_a_key_installs_from_the_constructed_url(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $server = $this->forgeServer();
        $this->fakePack([], [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(6000001, 301, 'cf-only.jar', 'cf-bytes')])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(301, 'CF Only')])),
            CurseForge::url(6000001, 'cf-only.jar') => Http::response('cf-bytes'),
        ]);

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('cf-bytes', $this->files->files['mods/cf-only.jar'] ?? null);
        $this->assertSame([], $install->summary['manual_files'] ?? null);
        $this->assertSame([], $this->files->callsOf('pull'));
    }
}
