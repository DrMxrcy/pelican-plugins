<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\AtLauncher;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 32: an ATLauncher pack installs through the item-30 engine: its mods (verified by
 * CurseForge's sha1, fetched from ATLauncher's CDN by the panel), without the ones marked
 * `server: false`, plus its configs archive applied from the zip root.
 */
class AtLauncherInstallTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
    }

    private function forgeServer(): Server
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed([
            'mods/old.jar' => 'old',
            'world/level.dat' => 'level',
            'server.properties' => "level-name=world\n",
        ]);

        return $server;
    }

    /** @param  array<string, mixed>  $bodies  URL => body served instead of the file */
    private function fakePack(array $bodies = []): void
    {
        $zip = Mrpack::zip([
            'config/a.toml' => 'a=1',
            'server.properties' => 'level-name=evil',
            'start.sh' => '#!/bin/sh',
        ]);
        $mods = [
            AtLauncher::mod('cf-mod.jar', 'cf-bytes', 301, 6000001),
            AtLauncher::mod('client.jar', 'client-bytes', 302, 6000002, ['server' => false]),
        ];

        Http::swap(new HttpFactory());
        Http::fake([
            AtLauncher::API.'pack/'.AtLauncher::PACK => Http::response(AtLauncher::data(AtLauncher::pack())),
            AtLauncher::API.'packs/full/public' => Http::response(AtLauncher::data([AtLauncher::pack()])),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/'.AtLauncher::VERSION.'/Configs.json' => Http::response(AtLauncher::configs($mods, $zip)),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/'.AtLauncher::VERSION.'/Configs.zip' => Http::response($zip),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/files/cf-mod.jar' => Http::response($bodies['cf-mod.jar'] ?? 'cf-bytes'),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(6000001, 301, 'cf-mod.jar', 'cf-bytes')])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(301, 'CF Mod')])),
            'api.modrinth.com/v2/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    private function install(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, AtLauncherPackProvider::ID, AtLauncher::PACK, AtLauncher::VERSION, 'Test Pack', AtLauncher::VERSION);
        $this->runJobs(between: fn () => $this->finishBackups($server));

        return $this->latestInstall();
    }

    public function test_installs_an_atlauncher_pack_end_to_end(): void
    {
        $server = $this->forgeServer();
        $this->fakePack();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('cf-bytes', $this->files->files['mods/cf-mod.jar'] ?? null);
        $this->assertArrayNotHasKey('mods/client.jar', $this->files->files);
        $this->assertSame('a=1', $this->files->files['config/a.toml'] ?? null);
        $this->assertSame("level-name=world\n", $this->files->files['server.properties']);
        $this->assertArrayNotHasKey('start.sh', $this->files->files);
        $this->assertSame([], $this->files->violations);
        $this->assertSame([], $this->files->callsOf('pull'));
        // The CurseForge CDN was never used for an ATLauncher-hosted mod.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'forgecdn.net'));
        $this->assertFalse($install->summary['server_pack'] ?? true);
    }

    public function test_without_curseforge_an_md5_only_atlauncher_mod_installs_from_atlaunchers_cdn(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', null);
        $server = $this->forgeServer();
        $this->fakePack();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('cf-bytes', $this->files->files['mods/cf-mod.jar'] ?? null);
        $this->assertSame([], $this->files->callsOf('pull'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curseforge.com'));
        $this->assertSame(1, $install->summary['md5_verified'] ?? null);
        $this->assertStringContainsString('md5', (string) InstallProgress::summary($install, true));
    }

    public function test_an_md5_mismatch_from_atlaunchers_cdn_is_never_written(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', null);
        $server = $this->forgeServer();
        $this->fakePack(['cf-mod.jar' => 'cf-bytez']);

        $install = $this->install($server);

        $this->assertSame('hash_mismatch', $install->error);
        $this->assertArrayNotHasKey('mods/cf-mod.jar', $this->files->files);
    }

    public function test_an_atlauncher_mod_that_does_not_match_curseforges_sha1_is_never_written(): void
    {
        $server = $this->forgeServer();
        $this->fakePack(['cf-mod.jar' => 'cf-bytez']);

        $install = $this->install($server);

        $this->assertSame('hash_mismatch', $install->error);
        $this->assertArrayNotHasKey('mods/cf-mod.jar', $this->files->files);
    }
}
