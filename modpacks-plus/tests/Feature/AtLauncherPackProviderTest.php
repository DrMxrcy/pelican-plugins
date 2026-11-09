<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\AtLauncher;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 32 step 3: the ATLauncher provider. Mods marked `server: false` are left out, the
 * configs archive is applied from its root like a server pack, and every mod is verified by
 * a sha1 (ATLauncher's own, else CurseForge's for the file it names; md5 is never used) or
 * listed. Every answer is treated as hostile.
 */
class AtLauncherPackProviderTest extends TestCase
{
    /** @var array<string, array{string, string}> contents => [Modrinth project id, server_side] */
    private array $onModrinth = [];

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new HttpFactory());
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', false);
        $this->workDir = sys_get_temp_dir().'/mp-atl-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    private function provider(): AtLauncherPackProvider
    {
        /** @var AtLauncherPackProvider $provider */
        $provider = app(PackProviders::class)->get(AtLauncherPackProvider::ID);

        return $provider;
    }

    private function validator(?Caps $caps = null): PlanValidator
    {
        return new PlanValidator($caps ?? Caps::fromConfig(), ['world'], PlanValidator::hostsFor(AtLauncherPackProvider::ID));
    }

    /**
     * @param  array<int, array<string, mixed>>  $mods
     * @param  array<string, string>|null  $zipEntries  Configs.zip entries (null = no configs)
     * @param  array<string, mixed>  $stubs
     * @param  array<string, mixed>  $configOverrides
     */
    private function fake(array $mods, ?array $zipEntries = null, array $stubs = [], array $configOverrides = []): void
    {
        $zip = $zipEntries !== null ? Mrpack::zip($zipEntries) : null;

        Http::swap(new HttpFactory());
        Http::fake(array_merge($stubs, [
            AtLauncher::API.'pack/'.AtLauncher::PACK => Http::response(AtLauncher::data(AtLauncher::pack())),
            AtLauncher::API.'packs/full/public' => Http::response(AtLauncher::data([AtLauncher::pack()])),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/'.AtLauncher::VERSION.'/Configs.json' => Http::response(AtLauncher::configs($mods, $zip, $configOverrides)),
            AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/'.AtLauncher::VERSION.'/Configs.zip' => Http::response((string) $zip),
            'api.modrinth.com/v2/version_files' => function (Request $request) {
                $algorithm = $request->data()['algorithm'] ?? '';
                $answer = [];
                foreach ($this->onModrinth as $contents => [$project]) {
                    $hash = hash($algorithm === 'sha512' ? 'sha512' : 'sha1', $contents);
                    if (in_array($hash, $request->data()['hashes'] ?? [], true)) {
                        $answer[$hash] = [
                            'id' => 'VER00001',
                            'project_id' => $project,
                            'files' => [['url' => 'https://cdn.modrinth.com/data/'.$project.'/versions/VER00001/m.jar', 'size' => strlen($contents), 'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)]]],
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
        return $this->provider()->resolvePlan(AtLauncher::PACK, AtLauncher::VERSION, $this->validator($caps), $this->workDir);
    }

    /**
     * @param  array<int, mixed>  $entries  CurseForge files
     * @param  array<int, mixed>  $mods  CurseForge projects
     * @return array<string, mixed>
     */
    private function curseForge(array $entries, array $mods): array
    {
        return [
            CurseForge::API.'mods/files' => Http::response(CurseForge::data($entries)),
            CurseForge::API.'mods' => Http::response(CurseForge::data($mods)),
        ];
    }

    /**
     * @param  array<int, PlanFile>  $files
     * @return array<string, ?string>
     */
    private static function urls(array $files): array
    {
        $urls = [];
        foreach ($files as $file) {
            $urls[$file->path] = $file->url;
        }

        return $urls;
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

    public function test_search_filters_public_packs_with_the_plugin_user_agent(): void
    {
        Http::fake([
            AtLauncher::API.'packs/full/public' => Http::response(AtLauncher::data([
                AtLauncher::pack(),
                AtLauncher::pack('OtherPack', ['name' => 'Other', 'description' => 'nothing', 'versions' => [['version' => '1', 'minecraft' => '1.20.1', 'published' => 5]]]),
                AtLauncher::pack('Private', ['type' => 'private']),
                AtLauncher::pack('../evil'),
            ])),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->assertSame(['TestPack'], array_column($this->provider()->search('servers', '1.20.1', 'forge')['hits'], 'id'));
        $this->assertSame(['TestPack', 'OtherPack'], array_column($this->provider()->search(null, '1.20.1', null)['hits'], 'id'));
        $this->assertSame([], $this->provider()->search(null, '1.12.2', null)['hits']);

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $agent = $request->header('User-Agent')[0] ?? '';
            $this->assertStringStartsWith('DrMxrcy/modpacks-plus/', $agent);
            $this->assertStringNotContainsString('Mozilla', $agent);
            $this->assertSame(['identity'], $request->header('Accept-Encoding'));
        }
        // The list is read once (cached).
        Http::assertSentCount(1);
    }

    public function test_versions_carry_the_loader_from_configs_json(): void
    {
        $this->fake([]);
        Http::fake([AtLauncher::CDN.'packs/'.AtLauncher::PACK.'/versions/v0.9.0/Configs.json' => Http::response('nope', 404)]);

        $versions = $this->provider()->versions(AtLauncher::PACK);

        $this->assertSame([AtLauncher::VERSION, 'v0.9.0'], array_column($versions, 'id'));
        $this->assertSame(['forge'], $versions[0]['loaders']);
        $this->assertSame(['1.20.1'], $versions[0]['game_versions']);

        $this->assertSame(['forge'], $this->provider()->packVersion(AtLauncher::PACK, AtLauncher::VERSION)['loaders']);
        $this->assertSame('Test Pack', $this->provider()->project(AtLauncher::PACK)['title']);
    }

    public function test_server_false_and_optional_mods_are_left_out(): void
    {
        $this->fake([
            AtLauncher::mod('server-mod.jar', 'server-bytes', null, null, ['sha1' => sha1('server-bytes')]),
            AtLauncher::mod('client-mod.jar', 'client-bytes', null, null, ['sha1' => sha1('client-bytes'), 'server' => false]),
            AtLauncher::mod('optional.jar', 'optional-bytes', null, null, ['sha1' => sha1('optional-bytes'), 'optional' => true]),
            AtLauncher::mod('shaders.zip', 'shader-bytes', null, null, ['type' => 'shaderpack']),
            AtLauncher::mod('dp.zip', 'dp', null, null, ['type' => 'datapack']),
            AtLauncher::mod('old.jar', 'old', null, null, ['type' => 'coremods']),
            AtLauncher::mod('manual.jar', 'manual', null, null, ['download' => 'browser']),
        ]);

        $plan = $this->resolve();

        $this->assertSame(['mods/server-mod.jar' => AtLauncher::fileUrl('server-mod.jar')], self::urls($plan->files));
        $this->assertSame(PlanFile::VERIFY_PANEL, $plan->files[0]->verify);
        $this->assertSame(['sha1' => sha1('server-bytes')], $plan->files[0]->hashes);
        $this->assertSame(['mods/client-mod.jar', 'shaders.zip'], $plan->skippedClientFiles);
        $this->assertSame(['dp' => 'datapack', 'old' => 'unsupported_type', 'manual' => 'distribution_disabled'], self::reasons($plan->manualFiles));
        $this->assertSame(['atlauncher', 'TestPack', 'v1.0.0', 'Test Pack', '1.20.1', 'forge', '47.2.0'], [$plan->provider, $plan->packId, $plan->versionId, $plan->packName, $plan->minecraftVersion, $plan->loader, $plan->loaderVersion]);
        $this->assertNull($plan->archive);
    }

    public function test_md5_only_mods_need_curseforge_and_are_never_downloaded_without_a_sha1(): void
    {
        $this->fake([
            AtLauncher::mod('cf-mod.jar', 'cf-bytes', 301, 6000001),
            AtLauncher::mod('atl-only.jar', 'atl-bytes'),
        ]);

        $plan = $this->resolve();

        $this->assertSame([], $plan->files);
        $this->assertSame(['cf-mod' => 'curseforge_key_required', 'atl-only' => 'no_usable_hash'], self::reasons($plan->manualFiles));
        $this->assertSame('https://www.curseforge.com/minecraft/mc-mods/cf-mod', $plan->manualFiles[0]['url']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/files/'));
    }

    public function test_with_curseforge_a_mod_is_checked_against_curseforges_sha1(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->onModrinth = ['m-bytes' => ['PROJMMMM', 'required']];
        $this->fake([
            AtLauncher::mod('cf-mod.jar', 'cf-bytes', 301, 6000001),
            AtLauncher::mod('m-mod.jar', 'm-bytes', 302, 6000002),
            // On CurseForge's CDN: plan 31's rules (constructed URL, allowModDistribution).
            AtLauncher::mod('direct.jar', 'd-bytes', 303, 6000003, ['download' => 'direct', 'url' => 'https://edge.forgecdn.net/files/1/1/other.jar']),
            AtLauncher::mod('blocked.jar', 'b-bytes', 304, 6000004, ['download' => 'direct', 'url' => CurseForge::url(6000004, 'blocked.jar')]),
            AtLauncher::mod('elsewhere.jar', 'e-bytes', null, null, ['download' => 'direct', 'url' => 'https://evil.example/e.jar', 'sha1' => sha1('e-bytes')]),
        ], null, $this->curseForge([
            CurseForge::file(6000001, 301, 'cf-mod.jar', 'cf-bytes'),
            CurseForge::file(6000002, 302, 'm-mod.jar', 'm-bytes'),
            CurseForge::file(6000003, 303, 'direct.jar', 'd-bytes'),
            CurseForge::file(6000004, 304, 'blocked.jar', 'b-bytes', ['downloadUrl' => null]),
        ], [
            CurseForge::modEntry(301, 'CF Mod'), CurseForge::modEntry(302, 'M Mod'), CurseForge::modEntry(303, 'Direct'), CurseForge::modEntry(304, 'Blocked', false),
        ]));

        $plan = $this->resolve();

        $this->assertSame([
            'mods/cf-mod.jar' => AtLauncher::fileUrl('cf-mod.jar'),
            'mods/m-mod.jar' => 'https://cdn.modrinth.com/data/PROJMMMM/versions/VER00001/m.jar',
            'mods/direct.jar' => CurseForge::url(6000003, 'direct.jar'),
        ], self::urls($plan->files));
        $this->assertSame(['sha1' => sha1('cf-bytes')], $plan->files[0]->hashes);
        $this->assertSame(PlanFile::VERIFY_PANEL, $plan->files[0]->verify);
        $this->assertSame(PlanFile::VERIFY_MODRINTH, $plan->files[1]->verify);
        $this->assertSame(['blocked' => 'distribution_disabled', 'elsewhere' => 'host_not_allowed'], self::reasons($plan->manualFiles));
    }

    public function test_curseforge_disagreeing_with_atlaunchers_size_stops_the_install(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        $this->fake([AtLauncher::mod('cf-mod.jar', 'cf-bytes', 301, 6000001)], null, $this->curseForge(
            [CurseForge::file(6000001, 301, 'cf-mod.jar', 'cf-bytes-but-longer')],
            [CurseForge::modEntry(301, 'CF Mod')],
        ));

        $this->expectExceptionObject(new UnsafePlanException('hash_mismatch', 'mods/cf-mod.jar'));
        $this->resolve();
    }

    public function test_the_configs_archive_is_applied_from_its_root_like_a_server_pack(): void
    {
        $this->fake([], [
            'config/a.toml' => 'a=1',
            'scripts/x.zs' => 'recipes',
            'server-icon.png' => 'png',
            'start.sh' => '#!/bin/sh',
            'libraries/x.jar' => 'lib',
            '.env' => 'SECRET=1',
            'server.properties' => 'level-name=evil',
            'world/region/r.0.0.mca' => 'region',
            'options.txt' => 'gui',
            'resourcepacks/rp.zip' => 'rp',
            'mods/.modrinth-metadata.json' => '{}',
        ]);

        $plan = $this->resolve();

        $this->assertNotNull($plan->archive);
        $this->assertSame(AtLauncher::configsUrl('Configs.zip'), $plan->archive->url);
        $this->assertArrayHasKey('sha1', $plan->archive->hashes);
        $this->assertSame('', $plan->overrideRoot());
        $this->assertNull($plan->serverPack);
        $this->assertEqualsCanonicalizing(['config/a.toml', 'scripts/x.zs', 'server-icon.png'], array_column(array_map(fn ($entry) => $entry->toArray(), $plan->overrides), 'path'));
        $this->assertEqualsCanonicalizing(['start.sh', 'libraries/x.jar', '.env'], $plan->skippedPackFiles);
        $this->assertEqualsCanonicalizing(['server.properties', 'world/region/r.0.0.mca', 'mods/.modrinth-metadata.json'], $plan->skippedOverrides);
        $this->assertEqualsCanonicalizing(['options.txt', 'resourcepacks/rp.zip'], $plan->skippedClientOverrides);

        // A stored plan keeps its archive root.
        $this->assertSame('', InstallPlan::fromArray($plan->toArray())->overrideRoot());
    }

    public function test_a_zip_slip_entry_in_the_configs_archive_is_refused(): void
    {
        $this->fake([], ['config/a.toml' => 'a', '../../etc/passwd' => 'x']);

        $this->expectException(UnsafePlanException::class);
        $this->resolve();
    }

    public function test_a_configs_archive_without_a_sha1_is_refused_before_it_is_fetched(): void
    {
        $this->fake([], ['config/a.toml' => 'a'], [], ['configs' => ['filesize' => 10, 'md5' => md5('x')]]);

        try {
            $this->resolve();
            $this->fail('An archive without a sha1 was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('bad_hash', $exception->reason);
        }
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'Configs.zip'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function hostileMods(): array
    {
        return [
            'file traversal' => [['file' => '../../x.jar']],
            'file separator' => [['file' => 'a/b.jar']],
            'dotfile' => [['file' => '.x.jar']],
            'not a jar' => [['file' => 'x.sh']],
            'backslash' => [['file' => '..\\x.jar']],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('hostileMods')]
    public function test_hostile_mod_names_are_refused(array $overrides): void
    {
        $this->fake([AtLauncher::mod('x.jar', 'x', null, null, $overrides + ['sha1' => sha1('x')])]);

        try {
            $this->resolve();
            $this->fail('A hostile mod was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('unsafe_path', $exception->reason);
        }
    }

    public function test_hostile_cdn_paths_are_listed_and_never_fetched(): void
    {
        $this->fake([
            AtLauncher::mod('a.jar', 'a', null, null, ['sha1' => sha1('a'), 'url' => '../../../etc/a.jar']),
            AtLauncher::mod('b.jar', 'b', null, null, ['sha1' => sha1('b'), 'url' => 'https://evil.example/b.jar']),
            AtLauncher::mod('c.jar', 'c', null, null, ['sha1' => sha1('c'), 'url' => '/etc/c.jar']),
            AtLauncher::mod('d.jar', 'd', null, null, ['sha1' => sha1('d'), 'url' => 'packs/x/%2e%2e/d.jar']),
        ]);

        $plan = $this->resolve();

        $this->assertSame([], $plan->files);
        $this->assertSame(['a' => 'host_not_allowed', 'b' => 'host_not_allowed', 'c' => 'host_not_allowed', 'd' => 'host_not_allowed'], self::reasons($plan->manualFiles));
        $this->assertSame('https://download.nodecdn.net/containers/atl/packs/A%20B/x%2By.jar', AtLauncherPackProvider::cdnUrl('packs/A B/x+y.jar'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badConfigs(): array
    {
        return [
            'other minecraft' => [['minecraft' => '1.19.2']],
            'unknown loader' => [['loader' => ['type' => 'liteloader', 'metadata' => ['version' => '1']]]],
            'no loader' => [['loader' => null]],
            'loader to choose' => [['loader' => ['type' => 'forge', 'choose' => true, 'metadata' => ['version' => '47.2.0']]]],
            'hostile loader version' => [['loader' => ['type' => 'forge', 'metadata' => ['version' => '47.2.0 && rm -rf /']]]],
            'loader for other minecraft' => [['loader' => ['type' => 'forge', 'metadata' => ['minecraft' => '1.19.2', 'version' => '43.1.0']]]],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('badConfigs')]
    public function test_a_configs_json_without_a_usable_loader_is_refused(array $overrides): void
    {
        $this->fake([], null, [], $overrides);

        try {
            $this->resolve();
            $this->fail('Bad Configs.json was accepted');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('bad_index', $exception->reason);
        }
    }

    public function test_an_unlisted_version_is_refused(): void
    {
        $this->fake([]);

        $this->expectExceptionObject(new PackUnavailableException('version_mismatch', 'v9.9.9'));
        $this->provider()->resolvePlan(AtLauncher::PACK, 'v9.9.9', $this->validator(), $this->workDir);
    }

    public function test_too_many_mods_are_refused_before_looping(): void
    {
        config()->set('modpacks-plus.caps.max_files', 2);
        $this->fake(array_fill(0, 3, AtLauncher::mod('a.jar', 'a')));

        $this->expectExceptionObject(new UnsafePlanException('too_many_files', '3'));
        $this->resolve();
    }

    public function test_ids_that_are_not_plain_segments_never_reach_a_request(): void
    {
        Http::fake(['*' => Http::response('unexpected', 500)]);

        foreach (['../x', 'a/b', '..', '.x', 'a b', 'a%2f', str_repeat('a', 65)] as $id) {
            $this->assertFalse(AtLauncherPackProvider::isValidId($id), $id);
            try {
                $this->provider()->resolvePlan($id, AtLauncher::VERSION, $this->validator(), $this->workDir);
                $this->fail("$id was accepted");
            } catch (PackUnavailableException $exception) {
                $this->assertSame('atlauncher_not_found', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }
}
