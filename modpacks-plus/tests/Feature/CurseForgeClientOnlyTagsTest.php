<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 48: a CurseForge manifest install leaves out files whose own CurseForge file record
 * tags them `Client` and not `Server` (gameVersions), as live on Just Create SMP 0.5 where
 * reforgedplaymod (["Client"]) killed a NeoForge server at boot. Client+Server, Server-only
 * and untagged files stay; the tag is only read from CurseForge's file record. The same
 * holds through the mirror and for FTB packs that take CurseForge files.
 */
class CurseForgeClientOnlyTagsTest extends TestCase
{
    private const MIRROR = 'api.curse.tools/v1/';

    /** @var array<string, array{string, string}> contents => [Modrinth project id, server_side] */
    private array $onModrinth = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new HttpFactory());
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        config()->set('modpacks-plus.curseforge.mirror', false);
    }

    /**
     * The Just Create SMP shape: [project, file, name, environment tags].
     *
     * @return array<int, array{int, int, string, array<int, string>}>
     */
    private static function justCreate(): array
    {
        return [
            [301, 6000001, 'reforgedplaymod-1.21.1-0.3.jar', ['Client']],
            [302, 6000002, 'create-1.21.1-6.0.4.jar', ['Client', 'Server']],
            [303, 6000003, 'ash_api-neoforge-1.21.1.jar', []],
            [304, 6000004, 'create_oxidized-1.21.1.jar', ['Server']],
        ];
    }

    /**
     * CurseForge file records for the Just Create SMP shape.
     *
     * @param  array<string, mixed>  $overrides  file name => changes to its record
     * @return array<int, array<string, mixed>>
     */
    private static function cfFiles(array $overrides = []): array
    {
        return array_map(fn (array $mod) => CurseForge::file($mod[1], $mod[0], $mod[2], $mod[2].'-bytes', array_merge(
            ['gameVersions' => array_merge(['1.21.1', 'NeoForge'], $mod[3])],
            $overrides[$mod[2]] ?? [],
        )), self::justCreate());
    }

    /** @return array<int, array<string, mixed>> */
    private static function cfMods(): array
    {
        return array_map(fn (array $mod) => CurseForge::modEntry($mod[0], ucfirst(explode('-', $mod[2])[0])), self::justCreate());
    }

    /** Modrinth knowing only $this->onModrinth. */
    private function modrinthStub(): callable
    {
        return function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/version_files') {
                $algorithm = $request->data()['algorithm'] ?? '';
                $answer = [];
                foreach ($this->onModrinth as $contents => [$project]) {
                    $hash = hash($algorithm === 'sha512' ? 'sha512' : 'sha1', $contents);
                    if (in_array($hash, $request->data()['hashes'] ?? [], true)) {
                        $answer[$hash] = [
                            'id' => 'VER00001',
                            'project_id' => $project,
                            'files' => [['url' => 'https://cdn.modrinth.com/data/'.$project.'/versions/VER00001/'.substr(sha1($contents), 0, 8).'.jar', 'size' => strlen($contents), 'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)]]],
                        ];
                    }
                }

                return $answer === [] ? Http::response('{}', 200, ['Content-Type' => 'application/json']) : Http::response($answer);
            }
            if (str_starts_with($path, '/v2/projects')) {
                $projects = [];
                foreach ($this->onModrinth as [$project, $side]) {
                    $projects[] = ['id' => $project, 'slug' => strtolower($project), 'server_side' => $side];
                }

                return Http::response($projects);
            }

            return Http::response('unexpected', 500);
        };
    }

    /**
     * A CurseForge pack (no server pack) whose manifest lists the Just Create SMP shape.
     *
     * @param  array<string, mixed>  $overrides  file name => changes to its record
     */
    private function fakePack(string $api, array $overrides = []): void
    {
        $zip = Mrpack::zip(['manifest.json' => CurseForge::manifest(array_map(fn (array $mod) => [$mod[0], $mod[1]], self::justCreate()), 'neoforge-21.1.172', '1.21.1')]);
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip);

        Http::fake([
            $api.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            $api.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            $api.'mods/files' => Http::response(CurseForge::data(self::cfFiles($overrides))),
            $api.'mods' => Http::response(CurseForge::data(self::cfMods())),
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => fn () => Http::response($zip),
            'api.modrinth.com/*' => $this->modrinthStub(),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    private function resolve(string $provider): InstallPlan
    {
        $source = app(PackProviders::class)->get($provider);
        $pack = $provider === FtbPackProvider::ID ? (string) Ftb::PACK_ID : (string) CurseForge::PACK_ID;
        $version = $provider === FtbPackProvider::ID ? (string) Ftb::VERSION_ID : (string) CurseForge::FILE_ID;

        return $source->resolvePlan($pack, $version, new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor($provider)), sys_get_temp_dir().'/mp-cf-tags-'.bin2hex(random_bytes(4)));
    }

    /** @return array<int, string> */
    private static function paths(InstallPlan $plan): array
    {
        return array_map(fn (PlanFile $file) => $file->path, $plan->files);
    }

    private function assertOnlyTheClientModIsSkipped(InstallPlan $plan): void
    {
        $this->assertSame(['mods/create-1.21.1-6.0.4.jar', 'mods/ash_api-neoforge-1.21.1.jar', 'mods/create_oxidized-1.21.1.jar'], self::paths($plan));
        $this->assertSame(['mods/reforgedplaymod-1.21.1-0.3.jar'], $plan->skippedClientFiles);
        $this->assertSame([['path' => 'mods/reforgedplaymod-1.21.1-0.3.jar', 'project' => 'reforgedplaymod', 'source' => 'curseforge']], $plan->clientOnlyBy);
        $this->assertSame([], $plan->manualFiles);
        // Never downloaded.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'reforgedplaymod'));
    }

    public function test_a_file_curseforge_tags_client_only_is_skipped_and_listed_with_a_key(): void
    {
        $this->fakePack(CurseForge::API);

        $this->assertOnlyTheClientModIsSkipped($this->resolve(CurseForgePackProvider::ID));
    }

    public function test_a_file_curseforge_tags_client_only_is_skipped_and_listed_through_the_mirror(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', true);
        $this->fakePack(self::MIRROR);

        $this->assertOnlyTheClientModIsSkipped($this->resolve(CurseForgePackProvider::ID));
    }

    public function test_the_tags_are_read_case_insensitively_and_from_sortable_game_versions(): void
    {
        $this->fakePack(CurseForge::API, [
            // Only in sortableGameVersions: still CurseForge's own file record.
            'reforgedplaymod-1.21.1-0.3.jar' => ['gameVersions' => ['1.21.1', 'NeoForge'], 'sortableGameVersions' => [['gameVersionName' => 'Client', 'gameVersion' => '']]],
            // "server" in any case keeps the file.
            'create-1.21.1-6.0.4.jar' => ['gameVersions' => ['1.21.1', 'NeoForge', 'CLIENT'], 'sortableGameVersions' => [['gameVersionName' => 'server']]],
        ]);

        $this->assertOnlyTheClientModIsSkipped($this->resolve(CurseForgePackProvider::ID));
    }

    public function test_curseforge_client_only_wins_over_a_modrinth_side_that_keeps_the_file(): void
    {
        // Same precedence as pack markers: the pack's own source says client-only, Modrinth can't put it back.
        $this->onModrinth = ['reforgedplaymod-1.21.1-0.3.jar-bytes' => ['PROJREPL', 'required'], 'ash_api-neoforge-1.21.1.jar-bytes' => ['PROJASHA', 'unsupported']];
        $this->fakePack(CurseForge::API);

        $plan = $this->resolve(CurseForgePackProvider::ID);

        $this->assertSame(['mods/create-1.21.1-6.0.4.jar', 'mods/create_oxidized-1.21.1.jar'], self::paths($plan));
        $this->assertSame(['mods/reforgedplaymod-1.21.1-0.3.jar', 'mods/ash_api-neoforge-1.21.1.jar'], $plan->skippedClientFiles);
        $this->assertSame([
            ['path' => 'mods/reforgedplaymod-1.21.1-0.3.jar', 'project' => 'reforgedplaymod', 'source' => 'curseforge'],
            ['path' => 'mods/ash_api-neoforge-1.21.1.jar', 'project' => 'projasha'],
        ], $plan->clientOnlyBy);
    }

    public function test_tags_on_the_project_record_are_not_trusted(): void
    {
        $zip = Mrpack::zip(['manifest.json' => CurseForge::manifest([[303, 6000003]], 'neoforge-21.1.172', '1.21.1')]);
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip);
        Http::fake([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data([CurseForge::file(6000003, 303, 'ash_api.jar', 'x', ['gameVersions' => ['1.21.1', 'NeoForge']])])),
            CurseForge::API.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(303, 'Ash') + ['gameVersions' => ['Client'], 'sortableGameVersions' => [['gameVersionName' => 'Client']]]])),
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => fn () => Http::response($zip),
            'api.modrinth.com/*' => $this->modrinthStub(),
            '*' => Http::response('unexpected', 500),
        ]);

        $plan = $this->resolve(CurseForgePackProvider::ID);

        $this->assertSame(['mods/ash_api.jar'], self::paths($plan));
        $this->assertSame([], $plan->clientOnlyBy);
    }

    public function test_an_ftb_pack_taking_a_curseforge_file_tagged_client_only_skips_it(): void
    {
        $files = array_map(fn (array $mod) => Ftb::curseForgeMod($mod[2], $mod[2].'-bytes', $mod[0], $mod[1]), self::justCreate());
        Http::fake([
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version($files)),
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data(self::cfFiles())),
            CurseForge::API.'mods' => Http::response(CurseForge::data(self::cfMods())),
            'api.modrinth.com/*' => $this->modrinthStub(),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->assertOnlyTheClientModIsSkipped($this->resolve(FtbPackProvider::ID));
    }

    public function test_the_summary_names_the_curseforge_project_and_survives_a_round_trip(): void
    {
        $plan = new InstallPlan('curseforge', '1', '2', 'P', '1', '1.21.1', 'neoforge', null, clientOnlyBy: [
            ['path' => 'mods/reforgedplaymod.jar', 'project' => 'reforgedplaymod', 'source' => 'curseforge'],
            ['path' => 'mods/sodium.jar', 'project' => 'sodium'],
        ]);
        $this->assertSame($plan->clientOnlyBy, InstallPlan::fromArray($plan->toArray())->clientOnlyBy);

        $install = new PackInstall();
        $install->forceFill(['status' => PackInstall::STATUS_INSTALLED, 'summary' => ['client_only_by' => $plan->clientOnlyBy]]);
        $summary = (string) InstallProgress::summary($install);

        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.client_only_by', ['files' => 'mods/sodium.jar (sodium)'])), $summary);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.client_only_curseforge', ['files' => 'mods/reforgedplaymod.jar (reforgedplaymod)'])), $summary);
    }
}
