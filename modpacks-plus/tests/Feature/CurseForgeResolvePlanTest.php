<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\ModrinthMatcher;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 31 step 3: resolving a CurseForge pack version into a plan. Server pack first (API
 * only), else the manifest build: files placed by class, client-only classes skipped,
 * files CurseForge may not hand out listed for manual download (never scraped).
 */
class CurseForgeResolvePlanTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->workDir = sys_get_temp_dir().'/mp-cf-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    private function provider(?Caps $caps = null): CurseForgePackProvider
    {
        $caps ??= new Caps();

        return new CurseForgePackProvider(new CurseForgeClient(CurseForge::KEY), new ModrinthPackProvider(new ModrinthClient(), $caps), new ModrinthMatcher(new ModrinthClient()), $caps);
    }

    private function resolve(?Caps $caps = null): InstallPlan
    {
        $caps ??= new Caps();

        return $this->provider($caps)->resolvePlan((string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, new PlanValidator($caps, ['world'], PlanValidator::hostsFor('curseforge')), $this->workDir);
    }

    private function assertUnsafe(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());

            return;
        }

        $this->fail("Expected UnsafePlanException [$reason]");
    }

    private function assertUnavailable(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (PackUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());

            return;
        }

        $this->fail("Expected PackUnavailableException [$reason]");
    }

    /**
     * Requests only went to the CurseForge API (with the key), the CurseForge CDN and the
     * Modrinth API (without it); never to the CurseForge website.
     */
    private function assertOnlyAllowedRequests(): void
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $this->assertContains($host, ['api.curseforge.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net', 'api.modrinth.com', 'cdn.modrinth.com'], $request->url());
            $this->assertSame($host === 'api.curseforge.com' ? [CurseForge::KEY] : [], $request->header('x-api-key'), $request->url());
            $this->assertStringStartsWith('https://', $request->url());
        }
    }

    /**
     * A manifest pack: A (mod), B (mod, distribution off), C (resource pack), D (data pack),
     * E (optional mod), F (mod, distribution allowed but no downloadUrl), G (mod, unknown
     * distribution and no downloadUrl), H (distribution off but a downloadUrl anyway).
     *
     * @param  array<string, string>  $extraEntries
     * @param  array<string, mixed>  $packFile  changes to the pack's file payload
     * @param  array<string, mixed>  $stubs
     * @return array<string, array<string, mixed>> the file payloads by letter
     */
    private function fakeManifestPack(array $extraEntries = [], array $packFile = [], array $stubs = [], ?string $manifest = null): array
    {
        $files = [
            'A' => CurseForge::file(5000001, 201, 'a-mod-1.0.jar', 'a-bytes'),
            'B' => CurseForge::file(5000002, 202, 'b-mod.jar', 'b-bytes', ['downloadUrl' => null]),
            'C' => CurseForge::file(5000003, 203, 'C Pack.zip', 'c-bytes'),
            'D' => CurseForge::file(5000004, 204, 'd-data.zip', 'd-bytes'),
            'E' => CurseForge::file(5000005, 205, 'e-optional.jar', 'e-bytes'),
            'F' => CurseForge::file(5000006, 206, 'f mod+1.jar', 'f-bytes', ['downloadUrl' => null]),
            'G' => CurseForge::file(5000007, 207, 'g-mod.jar', 'g-bytes', ['downloadUrl' => null]),
            'H' => CurseForge::file(5000008, 208, 'h-mod.jar', 'h-bytes'),
        ];
        $mods = [
            CurseForge::modEntry(201, 'A Mod'),
            CurseForge::modEntry(202, 'B Mod', false),
            CurseForge::modEntry(203, 'C Pack', true, 12),
            CurseForge::modEntry(204, 'D Data', true, 6945),
            CurseForge::modEntry(205, 'E Mod'),
            CurseForge::modEntry(206, 'F Mod', true),
            CurseForge::modEntry(207, 'G Mod', null),
            CurseForge::modEntry(208, 'H Mod', false),
        ];

        $manifest ??= CurseForge::manifest([
            [201, 5000001], [202, 5000002], [203, 5000003], [204, 5000004], [205, 5000005, false], [206, 5000006], [207, 5000007], [208, 5000008],
        ]);
        $zip = Mrpack::zip(array_merge([
            'manifest.json' => $manifest,
            'modlist.html' => '<ul></ul>',
            'overrides/config/a.toml' => 'a=1',
            'overrides/ops.json' => '[]',
            'overrides/resourcepacks/x.zip' => 'x',
        ], $extraEntries));

        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip, $packFile);

        Http::fake(array_merge([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data(array_values($files))),
            CurseForge::API.'mods' => Http::response(CurseForge::data($mods)),
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => Http::response($zip),
            'api.modrinth.com/v2/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ], $stubs, ['*' => Http::response('unexpected', 500)]));

        return $files;
    }

    public function test_builds_the_client_pack_from_its_manifest(): void
    {
        $files = $this->fakeManifestPack();

        $plan = $this->resolve();

        $this->assertSame('curseforge', $plan->provider);
        $this->assertSame('1.20.1', $plan->minecraftVersion);
        $this->assertSame('forge', $plan->loader);
        $this->assertSame('47.2.0', $plan->loaderVersion);
        $this->assertSame('Test CF Pack', $plan->packName);
        $this->assertNull($plan->serverPack);

        $byPath = [];
        foreach ($plan->files as $file) {
            $byPath[$file->path] = $file;
        }
        $this->assertSame(['mods/a-mod-1.0.jar', 'mods/f mod+1.jar'], array_keys($byPath));

        $a = $byPath['mods/a-mod-1.0.jar'];
        $this->assertSame(CurseForge::url(5000001, 'a-mod-1.0.jar'), $a->url);
        $this->assertSame(['sha1' => sha1('a-bytes')], $a->hashes);
        $this->assertSame(strlen('a-bytes'), $a->size);
        $this->assertSame(PlanFile::VERIFY_PANEL, $a->verify);

        // Distribution allowed, no downloadUrl: the documented edge.forgecdn.net URL.
        $this->assertSame('https://edge.forgecdn.net/files/5000/6/f%20mod%2B1.jar', $byPath['mods/f mod+1.jar']->url);

        $this->assertSame(['resourcepacks/C Pack.zip'], $plan->skippedClientFiles);
        $this->assertSame([
            ['name' => 'd-data', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/d-data/files/5000004', 'reason' => 'datapack'],
            ['name' => 'b-mod', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/b-mod/files/5000002', 'reason' => 'distribution_disabled'],
            ['name' => 'g-mod', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/g-mod/files/5000007', 'reason' => 'distribution_disabled'],
            ['name' => 'h-mod', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/h-mod/files/5000008', 'reason' => 'distribution_disabled'],
        ], $plan->manualFiles);

        $this->assertSame(['config/a.toml'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
        $this->assertSame(['ops.json'], $plan->skippedOverrides);
        $this->assertSame(['resourcepacks/x.zip'], $plan->skippedClientOverrides);

        // The optional file was never resolved; nothing was fetched from a blocked file's host.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/mods/files') && !in_array(5000005, $request['fileIds'], true));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'h-mod.jar') || str_contains($request->url(), 'b-mod.jar'));
        $this->assertOnlyAllowedRequests();
    }

    public function test_a_pack_whose_author_blocks_downloads_is_never_fetched(): void
    {
        $this->fakeManifestPack(stubs: [
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod(overrides: ['allowModDistribution' => false]))),
        ]);

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_no_download');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'forgecdn'));
    }

    public function test_curseforge_only_files_over_the_panel_fetch_cap_are_listed_not_fatal(): void
    {
        $this->fakeManifestPack();

        $plan = $this->resolve(new Caps(maxPanelFetchBytes: 5));

        $this->assertSame([], $plan->files);
        $tooLarge = array_values(array_filter($plan->manualFiles, fn (array $entry) => $entry['reason'] === 'too_large'));
        $this->assertSame(['a-mod-1.0', 'f mod+1'], array_column($tooLarge, 'name'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'a-mod-1.0.jar'));
    }

    public function test_a_tampered_pack_archive_is_rejected(): void
    {
        $this->fakeManifestPack(packFile: ['hashes' => [['value' => sha1('something else'), 'algo' => 1]]]);

        $this->assertUnsafe(fn () => $this->resolve(), 'hash_mismatch');
    }

    public function test_a_pack_without_a_download_cannot_be_installed(): void
    {
        $this->fakeManifestPack(packFile: ['downloadUrl' => null]);

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_no_download');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'forgecdn'));
    }

    public function test_a_pack_archive_on_another_host_is_never_downloaded(): void
    {
        $this->fakeManifestPack(packFile: ['downloadUrl' => 'https://evil.example/files/1/2/pack.zip']);

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_no_download');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_an_unsafe_file_name_rejects_the_pack(): void
    {
        $this->fakeManifestPack(stubs: [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(5000001, 201, '../../evil.jar', 'x')])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod')])),
        ], manifest: CurseForge::manifest([[201, 5000001]]));

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    public function test_a_file_belonging_to_another_project_rejects_the_pack(): void
    {
        $this->fakeManifestPack(stubs: [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(5000001, 999, 'a.jar', 'x')])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod')])),
        ], manifest: CurseForge::manifest([[201, 5000001]]));

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_not_found');
    }

    public function test_a_missing_file_rejects_the_pack(): void
    {
        $this->fakeManifestPack(stubs: [CurseForge::API.'mods/files' => Http::response(CurseForge::data([]))], manifest: CurseForge::manifest([[201, 5000001]]));

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_not_found');
    }

    /** @return array<string, array{string}> */
    public static function badManifests(): array
    {
        return [
            'not json' => ['{'],
            'wrong type' => [CurseForge::manifest([], extra: ['manifestType' => 'other'])],
            'wrong version' => [CurseForge::manifest([], extra: ['manifestVersion' => 2])],
            'bad loader' => [CurseForge::manifest([], 'forge')],
            'unknown loader' => [CurseForge::manifest([], 'liteloader-1.0')],
            'hostile minecraft version' => [CurseForge::manifest([], 'forge-47.2.0', '../1.20.1')],
            'other overrides folder' => [CurseForge::manifest([], extra: ['overrides' => '../../etc'])],
            'bad ids' => [CurseForge::manifest([], extra: ['files' => [['projectID' => '1 OR 1', 'fileID' => 2]]])],
            'two primary loaders' => [CurseForge::manifest([], extra: ['minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'forge-1', 'primary' => true], ['id' => 'fabric-1', 'primary' => true]]]])],
        ];
    }

    #[DataProvider('badManifests')]
    public function test_a_bad_manifest_is_rejected(string $manifest): void
    {
        $this->fakeManifestPack(manifest: $manifest);

        $this->assertUnavailable(fn () => $this->resolve(), 'bad_index');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/mods/files'));
    }

    public function test_too_many_manifest_files_stop_before_any_lookup(): void
    {
        $this->fakeManifestPack(manifest: CurseForge::manifest([[201, 5000001], [202, 5000002], [203, 5000003]]));

        $this->assertUnsafe(fn () => $this->resolve(new Caps(maxFiles: 2)), 'too_many_files');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/mods/files'));
    }

    public function test_a_zip_slip_entry_in_the_pack_is_rejected(): void
    {
        $this->fakeManifestPack(['overrides/../../evil.txt' => 'x']);

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    /**
     * Fakes a manifest pack whose file names $serverZip as its server pack.
     *
     * @param  array<string, mixed>  $serverFile  changes to the server pack's file payload
     * @param  array<string, mixed>  $stubs
     */
    private function fakeServerPack(string $serverZip, array $serverFile = [], array $stubs = []): void
    {
        $server = CurseForge::file(CurseForge::SERVER_FILE_ID, CurseForge::PACK_ID, 'Test CF Pack-Server-1.0.0.zip', $serverZip, array_merge(['isServerPack' => true], $serverFile));

        $this->fakeManifestPack(packFile: ['serverPackFileId' => CurseForge::SERVER_FILE_ID], stubs: array_merge([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::SERVER_FILE_ID => Http::response(CurseForge::data($server)),
            CurseForge::url(CurseForge::SERVER_FILE_ID, 'Test CF Pack-Server-1.0.0.zip') => Http::response($serverZip),
        ], $stubs));
    }

    /** @param  array<string, string>  $extra */
    private static function serverZip(array $extra = []): string
    {
        return Mrpack::zip(array_merge([
            'Test CF Pack-Server/' => '',
            'Test CF Pack-Server/mods/a-mod-1.0.jar' => 'a-bytes',
            'Test CF Pack-Server/config/a.toml' => 'server a=2',
            'Test CF Pack-Server/startserver.sh' => '#!/bin/sh',
            'Test CF Pack-Server/forge-1.20.1-47.2.0-installer.jar' => 'installer',
            'Test CF Pack-Server/libraries/net/x.jar' => 'lib',
            'Test CF Pack-Server/eula.txt' => 'eula=true',
            'Test CF Pack-Server/server.properties' => 'level-name=evil',
        ], $extra));
    }

    public function test_prefers_the_server_pack(): void
    {
        $zip = self::serverZip();
        $this->fakeServerPack($zip);

        $plan = $this->resolve();

        $this->assertNotNull($plan->serverPack);
        $this->assertSame(['sha1' => sha1($zip)], $plan->serverPack->hashes);
        $this->assertSame('Test CF Pack-Server/', $plan->serverPackRoot);
        $this->assertSame('Test CF Pack-Server/', $plan->overrideRoot());
        $this->assertSame([], $plan->files);
        $this->assertSame([], $plan->manualFiles);
        $this->assertSame(['mods/a-mod-1.0.jar', 'config/a.toml'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
        $this->assertSame(['startserver.sh', 'forge-1.20.1-47.2.0-installer.jar', 'libraries/net/x.jar', 'eula.txt'], $plan->skippedPackFiles);
        $this->assertSame(['server.properties'], $plan->skippedOverrides);
        // The loader comes from the client pack's manifest; the egg installs it.
        $this->assertSame(['forge', '47.2.0'], [$plan->loader, $plan->loaderVersion]);
        $this->assertFileExists($this->workDir.'/server-pack.zip');

        // The manifest's files were never resolved: the server pack has them.
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/mods/files'));
        $this->assertOnlyAllowedRequests();
    }

    public function test_a_server_pack_at_the_zip_root_has_an_empty_root(): void
    {
        $this->fakeServerPack(Mrpack::zip(['mods/a.jar' => 'a', 'config/x.toml' => 'x', 'run.sh' => 'x']));

        $plan = $this->resolve();

        $this->assertSame('', $plan->serverPackRoot);
        $this->assertSame(['mods/a.jar', 'config/x.toml'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
    }

    public function test_a_server_pack_over_the_caps_falls_back_to_the_manifest(): void
    {
        $zip = self::serverZip(['Test CF Pack-Server/mods/huge.jar' => str_repeat('x', 4096)]);
        $this->fakeServerPack($zip);

        $plan = $this->resolve(new Caps(maxOverrideBytes: 1024));

        $this->assertNull($plan->serverPack);
        $this->assertSame(['reason' => 'override_too_large', 'detail' => 'mods/huge.jar'], $plan->serverPackFallback);
        $this->assertContains('mods/a-mod-1.0.jar', array_map(fn (PlanFile $file) => $file->path, $plan->files));
        $this->assertFileDoesNotExist($this->workDir.'/server-pack.zip');
    }

    public function test_a_server_pack_too_large_to_download_is_never_fetched(): void
    {
        $zip = self::serverZip();
        $this->fakeServerPack($zip, ['fileLength' => 200 * 1024 * 1024]);

        $plan = $this->resolve();

        $this->assertNull($plan->serverPack);
        $this->assertSame('archive_too_large', $plan->serverPackFallback['reason'] ?? null);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'Server-1.0.0.zip'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unusableServerPacks(): array
    {
        return [
            'not flagged as a server pack' => [['isServerPack' => false]],
            'another project' => [['modId' => 999]],
            'no download' => [['downloadUrl' => null]],
            'another host' => [['downloadUrl' => 'https://evil.example/files/4000/2/Server.zip']],
        ];
    }

    /** @param  array<string, mixed>  $changes */
    #[DataProvider('unusableServerPacks')]
    public function test_an_unusable_server_pack_falls_back_to_the_manifest(array $changes): void
    {
        $this->fakeServerPack(self::serverZip(), $changes);

        $plan = $this->resolve();

        $this->assertNull($plan->serverPack);
        $this->assertSame('server_pack_unusable', $plan->serverPackFallback['reason'] ?? null);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_a_missing_server_pack_falls_back_to_the_manifest(): void
    {
        $this->fakeServerPack(self::serverZip(), stubs: [
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::SERVER_FILE_ID => Http::response('{}', 404),
        ]);

        $this->assertSame('server_pack_unusable', $this->resolve()->serverPackFallback['reason'] ?? null);
    }

    public function test_a_server_pack_without_mods_falls_back_to_the_manifest(): void
    {
        $this->fakeServerPack(Mrpack::zip(['README.txt' => 'x']));

        $this->assertSame('server_pack_unusable', $this->resolve()->serverPackFallback['reason'] ?? null);
    }

    public function test_a_tampered_server_pack_fails_the_install(): void
    {
        $this->fakeServerPack(self::serverZip(), ['hashes' => [['value' => sha1('other'), 'algo' => 1]]]);

        $this->assertUnsafe(fn () => $this->resolve(), 'hash_mismatch');
    }

    public function test_a_zip_slip_server_pack_fails_the_install(): void
    {
        $this->fakeServerPack(self::serverZip(['Test CF Pack-Server/../../evil.sh' => 'x']));

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    public function test_curseforge_being_down_fails_without_guessing(): void
    {
        $this->fakeManifestPack(stubs: [CurseForge::API.'mods/files' => Http::response('down', 503)]);

        $this->assertUnavailable(fn () => $this->resolve(), 'curseforge_unavailable');
    }
}
