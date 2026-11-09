<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Plan 31 step 3: a CurseForge pack installs through the item-30 engine, built from its
 * manifest or from its server pack. CurseForge CDN files are fetched and hashed by the
 * panel (never pulled by the node) and the API key stays out of the install record and logs.
 */
class CurseForgeInstallTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function forgeServer(): Server
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed([
            'mods/old.jar' => 'old',
            'world/level.dat' => 'level',
            'ops.json' => '[]',
            'server.properties' => "level-name=world\n",
            'eula.txt' => 'eula=true',
        ]);

        return $server;
    }

    /**
     * A pack with one downloadable mod (A) and one whose author turned distribution off (B).
     *
     * @param  array<string, mixed>  $packFile
     * @param  array<string, mixed>  $stubs
     */
    private function fakePack(array $packFile = [], array $stubs = [], ?string $aBody = null): void
    {
        $files = [
            CurseForge::file(5000001, 201, 'a-mod.jar', 'a-bytes'),
            CurseForge::file(5000002, 202, 'b-mod.jar', 'b-bytes', ['downloadUrl' => null]),
        ];
        $zip = Mrpack::zip([
            'manifest.json' => CurseForge::manifest([[201, 5000001], [202, 5000002]]),
            'overrides/config/a.toml' => 'a=1',
            'overrides/ops.json' => '[{"name":"attacker"}]',
        ]);
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip, $packFile);

        Http::fake(array_merge([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data($files)),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod'), CurseForge::modEntry(202, 'B Mod', false)])),
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => Http::response($zip),
            CurseForge::url(5000001, 'a-mod.jar') => Http::response($aBody ?? 'a-bytes'),
            'api.modrinth.com/v2/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ], $stubs, ['*' => Http::response('unexpected', 500)]));
    }

    private function install(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, CurseForgePackProvider::ID, (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, 'Test CF Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));

        return $this->latestInstall();
    }

    private function assertKeyNeverStored(PackInstall $install): void
    {
        $record = (string) json_encode($install->fresh()?->toArray());
        $this->assertStringNotContainsString(CurseForge::KEY, $record);
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', $record);
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', (string) InstallProgress::summary($install, true));
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', (string) InstallProgress::log($install, 'UTC'));
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('SECRETkeyDoNotLeak', $line);
        }
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.curseforge.com') {
                $this->assertSame([], $request->header('x-api-key'), $request->url());
                $this->assertStringNotContainsString('SECRETkeyDoNotLeak', $request->url().$request->body());
            }
        }
    }

    public function test_installs_a_manifest_pack_end_to_end(): void
    {
        $server = $this->forgeServer();
        $this->fakePack();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('a-bytes', $this->files->files['mods/a-mod.jar'] ?? null);
        $this->assertArrayNotHasKey('mods/b-mod.jar', $this->files->files);
        $this->assertSame('a=1', $this->files->files['config/a.toml'] ?? null);
        $this->assertSame('[]', $this->files->files['ops.json']);
        $this->assertSame([], $this->files->violations);

        // The node never pulls a CurseForge CDN file; the panel fetched and hashed it.
        $this->assertSame([], $this->files->callsOf('pull'));
        Http::assertSent(fn (Request $request) => $request->url() === CurseForge::url(5000001, 'a-mod.jar'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'www.curseforge.com'));

        $summary = $install->summary ?? [];
        $this->assertSame([['name' => 'b-mod', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/b-mod/files/5000002', 'reason' => 'distribution_disabled']], $summary['manual_files'] ?? null);
        $html = (string) InstallProgress::summary($install);
        $this->assertStringContainsString('href="https://www.curseforge.com/minecraft/mc-mods/b-mod/files/5000002"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.manual_files', ['count' => 1])), $html);

        $this->assertKeyNeverStored($install);
    }

    public function test_a_tampered_curseforge_file_fails_the_install(): void
    {
        $server = $this->forgeServer();
        $this->fakePack(aBody: 'evil-bytes');

        $install = $this->install($server);

        // Capped at the declared size while streaming.
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('panel_fetch_too_large', $install->error);
        $this->assertArrayNotHasKey('mods/a-mod.jar', $this->files->files);
        $this->assertKeyNeverStored($install);
    }

    public function test_a_same_size_tampered_curseforge_file_fails_on_its_hash(): void
    {
        $server = $this->forgeServer();
        $this->fakePack(aBody: 'x-bytes');

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('hash_mismatch', $install->error);
        $this->assertArrayNotHasKey('mods/a-mod.jar', $this->files->files);
    }

    public function test_installs_from_the_server_pack(): void
    {
        $server = $this->forgeServer();
        $serverZip = Mrpack::zip([
            'Test CF Pack-Server/mods/a-mod.jar' => 'a-bytes',
            'Test CF Pack-Server/config/a.toml' => 'server a=2',
            'Test CF Pack-Server/startserver.sh' => '#!/bin/sh rm -rf /',
            'Test CF Pack-Server/forge-1.20.1-47.2.0-installer.jar' => 'installer',
            'Test CF Pack-Server/eula.txt' => 'eula=false',
            'Test CF Pack-Server/server.properties' => 'level-name=../evil',
            'Test CF Pack-Server/world/level.dat' => 'evil world',
        ]);
        $serverFile = CurseForge::file(CurseForge::SERVER_FILE_ID, CurseForge::PACK_ID, 'Server.zip', $serverZip, ['isServerPack' => true]);
        $this->fakePack(['serverPackFileId' => CurseForge::SERVER_FILE_ID], [
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::SERVER_FILE_ID => Http::response(CurseForge::data($serverFile)),
            CurseForge::url(CurseForge::SERVER_FILE_ID, 'Server.zip') => Http::response($serverZip),
        ]);

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('a-bytes', $this->files->files['mods/a-mod.jar'] ?? null);
        $this->assertSame('server a=2', $this->files->files['config/a.toml'] ?? null);
        foreach (['startserver.sh', 'forge-1.20.1-47.2.0-installer.jar'] as $launcher) {
            $this->assertArrayNotHasKey($launcher, $this->files->files);
        }
        $this->assertSame('eula=true', $this->files->files['eula.txt']);
        // Plan 36: the server pack's server.properties is merged, never written over the server's;
        // its level-name can't repoint the world, and the ports follow the allocation.
        $port = (int) $server->allocation?->port;
        $this->assertSame("level-name=world\nserver-port=$port\nquery.port=$port\n", $this->files->files['server.properties']);
        $this->assertSame("level-name=world\n", $this->files->files[$install->moved_aside_path.'/server.properties'] ?? null);
        $this->assertSame('level', $this->files->files['world/level.dat']);
        $this->assertSame([], $this->files->violations);

        $summary = $install->summary ?? [];
        $this->assertTrue($summary['server_pack'] ?? false);
        $this->assertSame(['startserver.sh', 'forge-1.20.1-47.2.0-installer.jar', 'eula.txt'], $summary['skipped_pack_files'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.server_pack')), (string) InstallProgress::summary($install));

        // The manifest's files were not resolved or downloaded: the server pack carries them.
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/mods/files') || $request->url() === CurseForge::url(5000001, 'a-mod.jar'));
        $this->assertKeyNeverStored($install);
    }

    public function test_a_pack_for_another_loader_is_refused_before_any_change(): void
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'fabric']);
        $this->files->seed(['server.properties' => "level-name=world\n", 'mods/old.jar' => 'old']);
        $this->fakePack();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('target_mismatch', $install->error);
        $this->assertSame('old', $this->files->files['mods/old.jar']);
        $this->assertSame(0, $this->backupsCreated);
    }
}
