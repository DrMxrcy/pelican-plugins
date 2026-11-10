<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JsonApiClient;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 32 step 1: the FTB provider. Search, details and versions come from the public
 * modpacks.ch API; a version's file list becomes a plan where client-only and optional files
 * are left out, FTB-hosted files are panel-fetched and sha1-checked, and CurseForge files go
 * through plan 31's rules (Modrinth-first, constructed CDN URL, allowModDistribution, or
 * "needs CurseForge key"). Every API answer is treated as hostile.
 */
class FtbPackProviderTest extends TestCase
{
    /** @var array<string, array{string, string}> contents => [Modrinth project id, server_side] */
    private array $onModrinth = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new HttpFactory());
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', false);
    }

    private function provider(): FtbPackProvider
    {
        /** @var FtbPackProvider $provider */
        $provider = app(PackProviders::class)->get(FtbPackProvider::ID);

        return $provider;
    }

    private function validator(?Caps $caps = null): PlanValidator
    {
        return new PlanValidator($caps ?? new Caps(), ['world'], PlanValidator::hostsFor(FtbPackProvider::ID));
    }

    private function modrinthUrl(string $contents): string
    {
        return 'https://cdn.modrinth.com/data/PROJ0001/versions/VER00001/'.substr(sha1($contents), 0, 8).'.jar';
    }

    /**
     * Fakes the FTB API for one version, Modrinth (knowing only $this->onModrinth) and,
     * through $stubs, anything else. Unexpected requests get a 500.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, mixed>  $stubs
     * @param  array<string, mixed>  $versionOverrides
     */
    private function fake(array $files, array $stubs = [], array $versionOverrides = []): void
    {
        Http::swap(new HttpFactory());
        Http::fake(array_merge($stubs, [
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version($files, $versionOverrides)),
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
            'api.modrinth.com/v2/version_files' => function (Request $request) {
                $algorithm = $request->data()['algorithm'] ?? '';
                $answer = [];
                foreach ($this->onModrinth as $contents => [$project]) {
                    $hash = hash($algorithm === 'sha512' ? 'sha512' : 'sha1', $contents);
                    if (in_array($hash, $request->data()['hashes'] ?? [], true)) {
                        $answer[$hash] = [
                            'id' => 'VER00001',
                            'project_id' => $project,
                            'files' => [['url' => $this->modrinthUrl($contents), 'size' => strlen($contents), 'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)]]],
                        ];
                    }
                }

                return $answer === [] ? Http::response('{}', 200, ['Content-Type' => 'application/json']) : Http::response($answer);
            },
            'api.modrinth.com/v2/projects*' => function () {
                $projects = [];
                foreach ($this->onModrinth as [$project, $side]) {
                    $projects[] = ['id' => $project, 'slug' => strtolower($project), 'server_side' => $side];
                }

                return Http::response($projects);
            },
            '*' => Http::response('unexpected', 500),
        ]));
    }

    private function resolve(?Caps $caps = null): InstallPlan
    {
        return $this->provider()->resolvePlan((string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, $this->validator($caps), sys_get_temp_dir());
    }

    /**
     * @param  array<int, PlanFile>  $files
     * @return array<int, string>
     */
    private static function paths(array $files): array
    {
        return array_map(fn (PlanFile $file) => $file->path, $files);
    }

    /**
     * @param  array<int, array{name: string, url: ?string, reason: string}>  $manual
     * @return array<string, string>
     */
    private static function reasons(array $manual): array
    {
        $reasons = [];
        foreach ($manual as $entry) {
            $reasons[$entry['name']] = $entry['reason'];
        }

        return $reasons;
    }

    public function test_search_lists_public_packs_with_the_plugin_user_agent(): void
    {
        Http::fake([
            Ftb::API.'modpack/search/100*' => Http::response(['packs' => [100, 'x', -3, 101], 'curseforge' => [290913], 'total' => 2]),
            Ftb::API.'modpack/100' => Http::response(Ftb::pack()),
            Ftb::API.'modpack/101' => Http::response(['status' => 'error', 'message' => 'not found'], 404),
            '*' => Http::response('unexpected', 500),
        ]);

        $result = $this->provider()->search('stone', '1.20.1', 'forge');

        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['hits']);
        $hit = $result['hits'][0];
        $this->assertSame('100', $hit['id']);
        $this->assertSame('FTB Test Pack', $hit['title']);
        $this->assertSame('FTB Team', $hit['author']);
        $this->assertSame('https://apps.modpacks.ch/modpacks/art/1/logo.png', $hit['icon_url']);
        $this->assertSame(['1.20.1'], $hit['game_versions']);
        $this->assertSame(['FTB', 'Tech'], $hit['categories']);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.modpacks.ch/public/modpack/search/100?term=stone'));
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $agent = $request->header('User-Agent')[0] ?? '';
            $this->assertStringStartsWith('DrMxrcy/modpacks-plus/', $agent);
            $this->assertStringNotContainsString('Mozilla', $agent);
            $this->assertSame(['identity'], $request->header('Accept-Encoding'));
        }
    }

    public function test_without_a_query_the_most_installed_packs_are_listed(): void
    {
        Http::fake([
            Ftb::API.'modpack/popular/installs/100' => Http::response(['packs' => [100]]),
            Ftb::API.'modpack/100' => Http::response(Ftb::pack()),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->assertSame('100', $this->provider()->search(null, null, null)['hits'][0]['id']);
    }

    public function test_search_without_a_version_or_loader_lists_packs_for_any_loader_with_a_bounded_term(): void
    {
        $pack = Ftb::pack();
        Http::fake([
            Ftb::API.'modpack/search/100*' => Http::response(['packs' => [100]]),
            Ftb::API.'modpack/100' => Http::response($pack),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->assertSame(['100'], array_column($this->provider()->search(str_repeat('s', 80), null, null)['hits'], 'id'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.modpacks.ch/public/modpack/search/100?term='.str_repeat('s', 64))
            && !str_contains($request->url(), str_repeat('s', 65)));
    }

    public function test_project_and_versions_are_read_and_cleaned(): void
    {
        $this->fake([]);

        $project = $this->provider()->project((string) Ftb::PACK_ID);
        $this->assertSame('FTB Test Pack', $project['title']);
        $this->assertSame(['forge'], $project['loaders']);
        // Only HTTPS images.
        $this->assertSame([['url' => 'https://apps.modpacks.ch/modpacks/art/1/splash.png', 'title' => null]], $project['gallery']);

        $versions = $this->provider()->versions((string) Ftb::PACK_ID);
        // Newest first, the private version hidden.
        $this->assertSame([(string) Ftb::VERSION_ID, (string) (Ftb::VERSION_ID - 1)], array_column($versions, 'id'));
        $this->assertSame(['1.20.1'], $versions[0]['game_versions']);
        $this->assertSame(['forge'], $versions[0]['loaders']);

        $version = $this->provider()->packVersion((string) Ftb::PACK_ID, (string) Ftb::VERSION_ID);
        $this->assertSame('1.0.0', $version['version_number']);
    }

    public function test_a_version_of_another_pack_is_refused(): void
    {
        Http::fake([
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version([], [], Ftb::VERSION_ID, 555)),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->expectExceptionObject(new PackUnavailableException('version_mismatch', (string) Ftb::VERSION_ID));
        $this->provider()->packVersion((string) Ftb::PACK_ID, (string) Ftb::VERSION_ID);
    }

    public function test_ids_that_are_not_plain_numbers_never_reach_a_request(): void
    {
        Http::fake(['*' => Http::response('unexpected', 500)]);

        foreach (['../1', '1/2', '0', 'abc', '1 ', '99999999999'] as $id) {
            try {
                $this->provider()->project($id);
                $this->fail("$id was accepted");
            } catch (PackUnavailableException $exception) {
                $this->assertSame('ftb_not_found', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_client_only_and_optional_files_are_left_out(): void
    {
        $this->fake([
            Ftb::file('./mods', 'server-mod.jar', 'server-mod', 'mod'),
            Ftb::file('./mods', 'minimap.jar', 'minimap', 'mod', ['clientonly' => true]),
            Ftb::file('./mods', 'optional.jar', 'optional', 'mod', ['optional' => true]),
            Ftb::file('./config', 'server.toml', 'a=1'),
            Ftb::file('./config', 'client.toml', 'b=1', 'config', ['clientonly' => true]),
            Ftb::file('./kubejs/server_scripts', 'main.js', 'onEvent()', 'script'),
            Ftb::file('.', 'start.sh', '#!/bin/sh', 'script'),
            Ftb::file('.', 'server.properties', 'level-name=evil'),
            Ftb::file('./world/datapacks', 'x.zip', 'dp', 'resource'),
            Ftb::file('./resourcepacks', 'rp.zip', 'rp', 'resource'),
            Ftb::file('.', 'options.txt', 'gui', 'config'),
        ]);

        $plan = $this->resolve();

        $this->assertSame(['mods/server-mod.jar', 'config/server.toml', 'kubejs/server_scripts/main.js'], self::paths($plan->files));
        $this->assertSame(['mods/minimap.jar', 'config/client.toml'], $plan->skippedClientFiles);
        $this->assertSame(['start.sh'], $plan->skippedPackFiles);
        $this->assertSame(['server.properties', 'world/datapacks/x.zip'], $plan->skippedOverrides);
        $this->assertSame(['resourcepacks/rp.zip', 'options.txt'], $plan->skippedClientOverrides);
        $this->assertNotContains('mods/optional.jar', self::paths($plan->files));

        // FTB's own files: panel-fetched (never the node), checked against FTB's sha1.
        foreach ($plan->files as $file) {
            $this->assertSame(PlanFile::VERIFY_PANEL, $file->verify);
            $this->assertSame('dist.modpacks.ch', parse_url((string) $file->url, PHP_URL_HOST));
            $this->assertArrayHasKey('sha1', $file->hashes);
        }
        $this->assertSame(['ftb', '100', '2000', 'FTB Test Pack', '1.0.0', '1.20.1', 'forge', '47.2.0'], [$plan->provider, $plan->packId, $plan->versionId, $plan->packName, $plan->versionNumber, $plan->minecraftVersion, $plan->loader, $plan->loaderVersion]);
        $this->assertNull($plan->archive);
        $this->assertSame([], $plan->overrides);
    }

    public function test_curseforge_files_without_a_key_are_listed_as_needing_one(): void
    {
        // A is on Modrinth (installs from there, no key needed); B is only on CurseForge.
        $this->onModrinth = ['a-bytes' => ['PROJAAAA', 'required']];
        $this->fake([
            Ftb::curseForgeMod('a-mod.jar', 'a-bytes', 201, 5000001),
            Ftb::curseForgeMod('b-mod.jar', 'b-bytes', 202, 5000002),
            Ftb::file('./config', 'a.toml', 'a=1'),
        ]);

        $plan = $this->resolve();

        $this->assertSame(['mods/a-mod.jar', 'config/a.toml'], self::paths($plan->files));
        $this->assertSame('https://cdn.modrinth.com', 'https://'.parse_url((string) $plan->files[0]->url, PHP_URL_HOST));
        $this->assertSame(hash('sha512', 'a-bytes'), $plan->files[0]->hashes['sha512']);
        $this->assertSame(PlanFile::VERIFY_MODRINTH, $plan->files[0]->verify);
        $this->assertSame(['b-mod.jar' => 'curseforge_key_required'], self::reasons($plan->manualFiles));

        // CurseForge was never asked (not set up), FTB's forgecdn URL never fetched.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curseforge.com') || str_contains($request->url(), 'forgecdn.net'));
    }

    public function test_modrinth_marks_a_curseforge_mod_client_only(): void
    {
        $this->onModrinth = ['m-bytes' => ['PROJMMMM', 'unsupported']];
        $this->fake([Ftb::curseForgeMod('minimap.jar', 'm-bytes', 209, 5000009)]);

        $plan = $this->resolve();

        $this->assertSame([], $plan->files);
        $this->assertSame(['mods/minimap.jar'], $plan->skippedClientFiles);
        $this->assertSame([['path' => 'mods/minimap.jar', 'project' => 'projmmmm']], $plan->clientOnlyBy);
    }

    public function test_curseforge_files_with_a_key_use_the_constructed_url_and_respect_distribution(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fake([
            // FTB points A at another forgecdn path: never used, CurseForge's id + name decide.
            Ftb::curseForgeMod('a-mod.jar', 'a-bytes', 201, 5000001, ['url' => 'https://edge.forgecdn.net/files/9999/999/evil.jar']),
            Ftb::curseForgeMod('b-mod.jar', 'b-bytes', 202, 5000002),
        ], [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([
                CurseForge::file(5000001, 201, 'a-mod.jar', 'a-bytes'),
                CurseForge::file(5000002, 202, 'b-mod.jar', 'b-bytes', ['downloadUrl' => null]),
            ])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod'), CurseForge::modEntry(202, 'B Mod', false)])),
        ]);

        $plan = $this->resolve();

        $this->assertSame(['mods/a-mod.jar'], self::paths($plan->files));
        $this->assertSame(CurseForge::url(5000001, 'a-mod.jar'), $plan->files[0]->url);
        $this->assertSame(['sha1' => sha1('a-bytes')], $plan->files[0]->hashes);
        $this->assertSame(PlanFile::VERIFY_PANEL, $plan->files[0]->verify);
        $this->assertSame([['name' => 'b-mod.jar', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/b-mod/files/5000002', 'reason' => 'distribution_disabled']], $plan->manualFiles);
    }

    public function test_curseforge_disagreeing_with_ftb_about_a_file_stops_the_install(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fake([Ftb::curseForgeMod('a-mod.jar', 'a-bytes', 201, 5000001)], [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(5000001, 201, 'a-mod.jar', 'other-bytes')])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod')])),
        ]);

        $this->expectExceptionObject(new UnsafePlanException('hash_mismatch', 'mods/a-mod.jar'));
        $this->resolve();
    }

    public function test_a_file_on_another_host_is_listed_and_never_fetched(): void
    {
        $this->fake([
            Ftb::file('./mods', 'evil.jar', 'evil', 'mod', ['url' => 'https://evil.example/evil.jar']),
            Ftb::file('./mods', 'plain.jar', 'plain', 'mod', ['url' => 'http://dist.modpacks.ch/plain.jar']),
            Ftb::file('./mods', 'userinfo.jar', 'u', 'mod', ['url' => 'https://dist.modpacks.ch@evil.example/u.jar']),
            Ftb::file('./mods', 'nourl.jar', 'n', 'mod', ['url' => '']),
            // A forgecdn URL without CurseForge ids can't go through plan 31's rules.
            Ftb::file('./mods', 'forge.jar', 'f', 'mod', ['url' => CurseForge::url(5000005, 'forge.jar')]),
        ]);

        $plan = $this->resolve();

        $this->assertSame([], $plan->files);
        $this->assertSame(['evil.jar' => 'host_not_allowed', 'plain.jar' => 'host_not_allowed', 'userinfo.jar' => 'host_not_allowed', 'nourl.jar' => 'host_not_allowed', 'forge.jar' => 'host_not_allowed'], self::reasons($plan->manualFiles));
        foreach ($plan->manualFiles as $entry) {
            $this->assertNull($entry['url']);
        }
    }

    public function test_a_file_without_a_sha1_is_listed_and_a_malformed_one_stops(): void
    {
        $this->fake([Ftb::file('./config', 'a.toml', 'a=1', 'config', ['sha1' => ''])]);
        $this->assertSame(['a.toml' => 'no_usable_hash'], self::reasons($this->resolve()->manualFiles));

        $this->fake([Ftb::file('./config', 'a.toml', 'a=1', 'config', ['sha1' => 'md5:'.md5('a=1')])]);
        $this->expectExceptionObject(new UnsafePlanException('bad_hash', 'config/a.toml'));
        $this->resolve();
    }

    public function test_a_file_over_the_panel_fetch_cap_is_listed(): void
    {
        config()->set('modpacks-plus.caps.max_panel_fetch_bytes', 4);
        $this->fake([Ftb::file('./mods', 'big.jar', 'big-bytes', 'mod')]);

        $plan = $this->resolve(Caps::fromConfig());

        $this->assertSame([], $plan->files);
        $this->assertSame(['big.jar' => 'too_large'], self::reasons($plan->manualFiles));
    }

    public function test_a_file_without_a_usable_size_is_listed_not_fatal(): void
    {
        $this->fake([
            Ftb::file('./config', 'ok.toml', 'ok'),
            Ftb::file('./config', 'nosize.toml', 'n', 'config', ['size' => 'big']),
            Ftb::file('./config', 'negative.toml', 'n', 'config', ['size' => -5]),
        ]);

        $plan = $this->resolve();

        $this->assertSame(['config/ok.toml'], self::paths($plan->files));
        $this->assertSame(['nosize.toml' => 'no_usable_size', 'negative.toml' => 'no_usable_size'], self::reasons($plan->manualFiles));
    }

    public function test_a_curseforge_file_curseforge_returns_nothing_for_is_listed_as_not_on_curseforge(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fake([Ftb::curseForgeMod('gone.jar', 'gone-bytes', 201, 5000001)], [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([])),
        ]);

        $this->assertSame([['name' => 'gone.jar', 'url' => null, 'reason' => 'not_on_curseforge']], $this->resolve()->manualFiles);
    }

    public function test_unknown_file_types_are_listed_not_placed(): void
    {
        $this->fake([Ftb::file('./mods', 'x.zip', 'x', 'cf-extract')]);

        $this->assertSame(['x.zip' => 'unsupported_type'], self::reasons($this->resolve()->manualFiles));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function hostileFiles(): array
    {
        return [
            'parent path' => [['path' => './../../etc', 'name' => 'passwd'], 'unsafe_path'],
            'parent in name' => [['path' => './mods', 'name' => '..'], 'unsafe_path'],
            'absolute path' => [['path' => '/etc', 'name' => 'passwd'], 'unsafe_path'],
            'separator in name' => [['path' => './mods', 'name' => '../x.jar'], 'unsafe_path'],
            'backslash' => [['path' => './mods', 'name' => '..\\x.jar'], 'unsafe_path'],
            'encoded' => [['path' => './%2e%2e', 'name' => 'x.jar'], 'unsafe_path'],
            'drive letter' => [['path' => 'C:', 'name' => 'x.jar'], 'unsafe_path'],
            'nul byte' => [['path' => './mods', 'name' => "x\0.jar"], 'unsafe_path'],
            'path not a string' => [['path' => ['mods'], 'name' => 'x.jar'], 'unsafe_path'],
            'mod that is not a jar' => [['path' => './mods', 'name' => 'x.sh', 'type' => 'mod'], 'unsafe_path'],
            'modrinth metadata' => [['path' => './mods', 'name' => '.modrinth-metadata.json'], 'pack_ships_metadata'],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('hostileFiles')]
    public function test_hostile_file_entries_are_refused(array $overrides, string $reason): void
    {
        $this->fake([Ftb::file('./config', 'ok.toml', 'ok'), array_merge(Ftb::file('./config', 'x', 'x'), $overrides)]);

        try {
            $this->resolve();
            $this->fail('A hostile entry was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'dist.modpacks.ch'));
    }

    public function test_duplicate_paths_are_refused(): void
    {
        $this->fake([Ftb::file('./config', 'a.toml', 'a'), Ftb::file('./config', 'A.toml', 'b')]);

        $this->expectExceptionObject(new UnsafePlanException('duplicate_path', 'config/A.toml'));
        $this->resolve();
    }

    public function test_a_file_list_longer_than_the_entry_cap_is_refused_before_looping(): void
    {
        config()->set('modpacks-plus.caps.max_archive_entries', 5);
        $this->fake(array_fill(0, 6, Ftb::file('./config', 'a.toml', 'a')));

        $this->expectExceptionObject(new UnsafePlanException('too_many_files', '6'));
        $this->resolve(Caps::fromConfig());
    }

    /** @return array<string, array{array<int, array<string, mixed>>}> */
    public static function badTargets(): array
    {
        return [
            'two loaders' => [[...Ftb::targets(), ['version' => '0.15.0', 'name' => 'fabric', 'type' => 'modloader']]],
            'no loader' => [[['version' => '1.20.1', 'name' => 'minecraft', 'type' => 'game']]],
            'unknown loader' => [Ftb::targets('liteloader')],
            'hostile loader version' => [Ftb::targets('forge', '47.2.0; rm -rf /')],
            'hostile minecraft version' => [Ftb::targets('forge', '47.2.0', '1.20.1/../x')],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $targets */
    #[DataProvider('badTargets')]
    public function test_a_version_without_exactly_one_known_loader_is_refused(array $targets): void
    {
        $this->fake([], [], ['targets' => $targets]);

        try {
            $this->resolve();
            $this->fail('Bad targets were accepted');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('bad_index', $exception->reason);
        }
    }

    public function test_a_private_version_is_not_found(): void
    {
        $this->fake([], [], ['private' => true]);

        $this->expectExceptionObject(new PackUnavailableException('ftb_not_found', Ftb::PACK_ID.'/'.Ftb::VERSION_ID));
        $this->resolve();
    }

    public function test_the_api_client_never_follows_redirects_or_decodes_and_caps_answers(): void
    {
        $client = new JsonApiClient(FtbPackProvider::API_URL, 'ftb_unavailable', 'ftb_not_found', 1024);
        $cases = [
            'redirect' => Http::response('', 302, ['Location' => 'https://evil.example/']),
            'gzip' => Http::response(gzencode('{"a":1}'), 200, ['Content-Encoding' => 'gzip']),
            'declared too large' => Http::response('{}', 200, ['Content-Length' => '4096']),
            'body too large' => Http::response(['x' => str_repeat('a', 2048)]),
            'not json' => Http::response('<html>'),
            'server error' => Http::response('{}', 503),
        ];

        foreach ($cases as $name => $response) {
            Http::swap(new HttpFactory());
            Http::fake(['*' => $response]);
            try {
                $client->get(['modpack', '100']);
                $this->fail("$name was accepted");
            } catch (PackUnavailableException $exception) {
                $this->assertSame('ftb_unavailable', $exception->reason, $name);
            }
        }

        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response('{}')]);
        foreach ([[], ['..'], ['modpack', '../x'], ['https:', '', 'evil.example'], ['a b'], ['.hidden']] as $segments) {
            try {
                $client->get($segments);
                $this->fail(json_encode($segments).' was requested');
            } catch (PackUnavailableException $exception) {
                $this->assertSame('ftb_unavailable', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }
}
