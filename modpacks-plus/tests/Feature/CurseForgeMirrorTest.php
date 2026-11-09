<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\ModpacksPlusPlugin;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\Support\RecordingPlugin;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 31 step 5: the opt-in key-free mirror (https://api.curse.tools/v1). Off by default,
 * metadata only, HTTPS only, never the redirecting /v1/cf form, a saved key always wins,
 * and a failing or rate-limiting mirror means "unavailable" (no install).
 */
class CurseForgeMirrorTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const MIRROR = 'api.curse.tools/v1/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', true);
    }

    private function provider(): CurseForgePackProvider
    {
        /** @var CurseForgePackProvider $provider */
        $provider = app(PackProviders::class)->get(CurseForgePackProvider::ID);

        return $provider;
    }

    private function catchUnavailable(callable $callback): PackUnavailableException
    {
        try {
            $callback();
        } catch (PackUnavailableException $exception) {
            return $exception;
        }

        $this->fail('Expected PackUnavailableException');
    }

    /** Every metadata request went to the mirror over HTTPS, without a key, never to /v1/cf. */
    private function assertMirrorOnly(): void
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $url = $request->url();
            $this->assertStringStartsWith('https://', $url);
            $this->assertSame([], $request->header('x-api-key'), $url);
            $this->assertStringNotContainsString('api.curseforge.com', $url);
            $this->assertDoesNotMatchRegularExpression('#^https://api\.curse\.tools/v1/cf(/|$)#i', $url);
            if (str_contains($url, 'curse.tools')) {
                $this->assertStringStartsWith('https://api.curse.tools/v1/', $url);
            }
        }
    }

    public function test_the_mirror_is_off_by_default(): void
    {
        $config = require dirname(__DIR__, 2).'/config/modpacks-plus.php';

        $this->assertFalse($config['curseforge']['mirror']);
        $this->assertFalse((new CurseForgeClient())->configured());
    }

    public function test_search_goes_to_the_mirror_without_a_key(): void
    {
        Http::fake([self::MIRROR.'mods/search*' => Http::response(['data' => [CurseForge::mod()], 'pagination' => ['totalCount' => 1]])]);

        $result = $this->provider()->search('pack', '1.20.1', 'forge');

        $this->assertSame((string) CurseForge::PACK_ID, $result['hits'][0]['id'] ?? null);
        $this->assertSame(CurseForgeClient::MODE_MIRROR, CurseForgeClient::fromConfig()->mode());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.curse.tools/v1/mods/search?'));
        $this->assertMirrorOnly();
    }

    public function test_a_saved_key_always_wins(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
        Http::fake([CurseForge::API.'mods/search*' => Http::response(['data' => []]), '*' => Http::response('unexpected', 500)]);

        $this->provider()->search(null, null, null);

        $this->assertSame(CurseForgeClient::MODE_KEY, CurseForgeClient::fromConfig()->mode());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.curseforge.com/v1/'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'curse.tools'));
    }

    /** @return array<string, array{string}> */
    public static function redirectingPaths(): array
    {
        return ['root' => ['cf'], 'mod' => ['cf/mods/1'], 'search' => ['cf/mods/search']];
    }

    #[DataProvider('redirectingPaths')]
    public function test_the_redirecting_cf_form_is_never_requested(string $path): void
    {
        Http::fake();

        $this->assertSame('curseforge_unavailable', $this->catchUnavailable(fn () => CurseForgeClient::fromConfig()->get($path))->reason);
        Http::assertNothingSent();
    }

    public function test_api_urls_exclude_the_cf_form(): void
    {
        $base = CurseForgeClient::MIRROR_URL;

        $this->assertTrue(CurseForgeClient::isApiUrl($base.'/mods/1', $base));
        $this->assertTrue(CurseForgeClient::isApiUrl($base.'/mods/search?x=1', $base));
        foreach (['/cf/mods/1', '/cf', '/CF/mods', '/cf?x=1'] as $path) {
            $this->assertFalse(CurseForgeClient::isApiUrl($base.$path, $base), $path);
        }
        $this->assertFalse(CurseForgeClient::isApiUrl('http://api.curse.tools/v1/mods/1', $base));
        $this->assertFalse(CurseForgeClient::isApiUrl('https://api.curse.tools/v10/mods', $base));
        $this->assertFalse(CurseForgeClient::isApiUrl('https://api.curse.tools.evil.example/v1/mods', $base));
    }

    public function test_a_mirror_redirect_is_never_followed(): void
    {
        Http::fake([
            self::MIRROR.'mods/1' => Http::response('', 302, ['Location' => 'http://api.curse.tools/v1/mods/1']),
            '*' => Http::response(CurseForge::data(CurseForge::mod())),
        ]);

        $this->assertSame('curseforge_mirror_unavailable', $this->catchUnavailable(fn () => CurseForgeClient::fromConfig()->get('mods/1'))->reason);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'http://'));
    }

    /** @return array<string, array{int}> */
    public static function mirrorFailures(): array
    {
        return ['rate limited' => [429], 'server error' => [500], 'bad gateway' => [502], 'unavailable' => [503], 'unauthorised' => [401], 'forbidden' => [403]];
    }

    #[DataProvider('mirrorFailures')]
    public function test_a_failing_mirror_means_unavailable(int $status): void
    {
        Http::fake(['*' => Http::response('nope', $status)]);

        $exception = $this->catchUnavailable(fn () => $this->provider()->search(null, null, null));

        $this->assertSame('curseforge_mirror_unavailable', $exception->reason);
        $this->assertStringContainsString('add an API key', trans('modpacks-plus::strings.reasons.curseforge_mirror_unavailable'));
    }

    public function test_an_unreachable_mirror_means_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertSame('curseforge_mirror_unavailable', $this->catchUnavailable(fn () => $this->provider()->search(null, null, null))->reason);
    }

    /**
     * A pack resolved through the mirror: mod A downloads from the CurseForge CDN, mod B's mirror
     * answer points its download at the mirror itself (never fetched), mod C's at plain HTTP.
     *
     * @param  array<string, mixed>  $stubs
     * @param  array<string, mixed>  $packFile  changes to the pack's file payload
     */
    private function fakeMirrorPack(array $stubs = [], array $packFile = []): void
    {
        $files = [
            CurseForge::file(5000001, 201, 'a-mod.jar', 'a-bytes'),
            CurseForge::file(5000002, 202, 'b-mod.jar', 'b-bytes', ['downloadUrl' => 'https://api.curse.tools/v1/files/5000002/download']),
            CurseForge::file(5000003, 203, 'c-mod.jar', 'c-bytes', ['downloadUrl' => 'http://edge.forgecdn.net/files/5000/3/c-mod.jar']),
        ];
        $zip = Mrpack::zip(['manifest.json' => CurseForge::manifest([[201, 5000001], [202, 5000002], [203, 5000003]]), 'overrides/config/a.toml' => 'a=1']);
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip, $packFile);

        Http::fake(array_merge([
            self::MIRROR.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            self::MIRROR.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            self::MIRROR.'mods/files' => Http::response(CurseForge::data($files)),
            self::MIRROR.'mods' => Http::response(CurseForge::data([CurseForge::modEntry(201, 'A Mod'), CurseForge::modEntry(202, 'B Mod', null), CurseForge::modEntry(203, 'C Mod', null)])),
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => fn () => Http::response($zip),
            CurseForge::url(5000001, 'a-mod.jar') => fn () => Http::response('a-bytes'),
            'api.modrinth.com/v2/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ], $stubs, ['*' => Http::response('unexpected', 500)]));
    }

    public function test_a_pack_resolves_through_the_mirror_with_downloads_only_from_allowlisted_hosts(): void
    {
        $this->fakeMirrorPack();

        $plan = $this->provider()->resolvePlan((string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor('curseforge')), sys_get_temp_dir().'/mp-mirror-'.bin2hex(random_bytes(4)));

        $this->assertSame(['mods/a-mod.jar'], array_map(fn (PlanFile $file) => $file->path, $plan->files));
        $this->assertSame('edge.forgecdn.net', parse_url((string) $plan->files[0]->url, PHP_URL_HOST));
        $this->assertSame(['b-mod', 'c-mod'], array_column($plan->manualFiles, 'name'));
        $this->assertMirrorOnly();
        // Metadata from the mirror, the pack archive from the CurseForge CDN; nothing downloaded from the mirror.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/download'));
        Http::assertSent(fn (Request $request) => $request->url() === CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME));
    }

    private function resolveThroughMirror(): InstallPlan
    {
        return $this->provider()->resolvePlan((string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor('curseforge')), sys_get_temp_dir().'/mp-mirror-'.bin2hex(random_bytes(4)));
    }

    /**
     * Mirror answers for the pack's three manifest files: the given ones, the rest blocked.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function mirrorFiles(array $files): array
    {
        $byId = [];
        foreach ([5000001 => 201, 5000002 => 202, 5000003 => 203] as $id => $modId) {
            $byId[$id] = CurseForge::file($id, $modId, 'blocked-'.$modId.'.jar', 'x', ['downloadUrl' => null]);
        }
        foreach ($files as $file) {
            $byId[$file['id']] = $file;
        }
        $files = array_values($byId);
        $mods = array_map(fn (array $file) => CurseForge::modEntry($file['modId'], 'Mod '.$file['modId'], str_starts_with($file['fileName'], 'blocked-') ? null : true), $files);

        return [
            self::MIRROR.'mods/files' => Http::response(CurseForge::data($files)),
            self::MIRROR.'mods' => Http::response(CurseForge::data($mods)),
        ];
    }

    public function test_a_mirror_download_url_naming_other_bytes_is_ignored(): void
    {
        // Same allowlisted host, but another file's path: the mirror can't redirect the install there.
        $files = [
            CurseForge::file(5000001, 201, 'a-mod.jar', 'a-bytes', ['downloadUrl' => 'https://edge.forgecdn.net/files/6666/666/a-mod.jar']),
            CurseForge::file(5000002, 202, 'b-mod.jar', 'b-bytes', ['downloadUrl' => 'https://edge.forgecdn.net/files/5000/2/other.jar']),
        ];
        $this->fakeMirrorPack($this->mirrorFiles($files));

        $plan = $this->resolveThroughMirror();

        $this->assertSame([], $plan->files);
        $this->assertSame(['a-mod', 'b-mod', 'blocked-203'], array_column($plan->manualFiles, 'name'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/files/6666/') || str_contains($request->url(), 'other.jar'));
    }

    public function test_the_constructed_url_is_the_one_fetched(): void
    {
        // The mirror names the right file on the other CDN host: the URL built from id and name is used.
        $files = [CurseForge::file(5000001, 201, 'a mod+1.jar', 'a-bytes', ['downloadUrl' => 'https://mediafilez.forgecdn.net/files/5000/1/a mod+1.jar'])];
        $this->fakeMirrorPack(array_merge($this->mirrorFiles($files), [
            'https://edge.forgecdn.net/files/5000/1/a%20mod%2B1.jar' => fn () => Http::response('a-bytes'),
        ]));

        $plan = $this->resolveThroughMirror();

        $this->assertSame('https://edge.forgecdn.net/files/5000/1/a%20mod%2B1.jar', $plan->files[0]->url ?? null);
        $this->assertSame(['sha1' => sha1('a-bytes')], $plan->files[0]->hashes);
    }

    public function test_a_pack_archive_url_for_another_file_is_never_used(): void
    {
        $this->fakeMirrorPack([
            self::MIRROR.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data(
                CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, 'zip', ['downloadUrl' => 'https://edge.forgecdn.net/files/1/1/evil.zip'])
            )),
        ]);

        $this->assertSame('curseforge_no_download', $this->catchUnavailable(fn () => $this->resolveThroughMirror())->reason);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'forgecdn'));
    }

    /** @return array<string, array{string}> */
    public static function hostileFileNames(): array
    {
        return [
            'executable' => ['a-mod.exe'],
            'no extension' => ['a-mod'],
            'script' => ['start.sh'],
            'backslash' => ['..\\..\\a.jar'],
            'slash' => ['../a.jar'],
            'control character' => ["a\n.jar"],
            'percent-encoded' => ['a%2f.jar'],
            'hidden' => ['.a.jar'],
            'too long' => [str_repeat('a', 300).'.jar'],
        ];
    }

    #[DataProvider('hostileFileNames')]
    public function test_a_hostile_file_name_rejects_the_pack(string $name): void
    {
        $files = [CurseForge::file(5000001, 201, $name, 'a-bytes', ['downloadUrl' => null])];
        $this->fakeMirrorPack($this->mirrorFiles($files));

        try {
            $this->resolveThroughMirror();
            $this->fail('Expected UnsafePlanException');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('unsafe_path', $exception->reason);
        }
    }

    public function test_the_mirror_never_uses_a_server_pack(): void
    {
        $this->fakeMirrorPack(packFile: ['serverPackFileId' => CurseForge::SERVER_FILE_ID]);

        $plan = $this->resolveThroughMirror();

        $this->assertNull($plan->serverPack);
        $this->assertSame(['reason' => 'server_pack_mirror', 'detail' => ''], $plan->serverPackFallback);
        $this->assertSame(['mods/a-mod.jar'], array_map(fn (PlanFile $file) => $file->path, $plan->files));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), (string) CurseForge::SERVER_FILE_ID) || str_contains($request->url(), (string) intdiv(CurseForge::SERVER_FILE_ID, 1000).'/2/'));
    }

    public function test_installs_through_the_mirror(): void
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n"]);
        $this->fakeMirrorPack();

        app(InstallEngine::class)->start($server, null, CurseForgePackProvider::ID, (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, 'Test CF Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));
        $install = $this->latestInstall();

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('a-bytes', $this->files->files['mods/a-mod.jar'] ?? null);
        $this->assertSame('a=1', $this->files->files['config/a.toml'] ?? null);
        $this->assertSame([], $this->files->callsOf('pull'));
        $this->assertMirrorOnly();
    }

    public function test_a_rate_limited_mirror_stops_the_install_before_any_change(): void
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n", 'mods/old.jar' => 'old']);
        $this->fakeMirrorPack([self::MIRROR.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response('slow down', 429)]);

        app(InstallEngine::class)->start($server, null, CurseForgePackProvider::ID, (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, 'Test CF Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));
        $install = $this->latestInstall();

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('curseforge_mirror_unavailable', $install->error);
        $this->assertSame(0, $this->backupsCreated);
        $this->assertSame('old', $this->files->files['mods/old.jar']);
    }

    public function test_the_mirror_setting_is_saved_and_shown(): void
    {
        config()->set('modpacks-plus.curseforge.mirror', true);
        $this->assertTrue((new ModpacksPlusPlugin())->getSettingsFormData()['curseforge_mirror']);

        $plugin = new RecordingPlugin();
        $plugin->saveSettings(['curseforge_mirror' => true]);
        $this->assertSame('true', $plugin->written[CurseForgeClient::MIRROR_ENV] ?? null);
        $plugin->saveSettings([]);
        $this->assertSame('false', $plugin->written[CurseForgeClient::MIRROR_ENV] ?? null);

        foreach (['no uptime guarantee', 'API terms', 'api.curse.tools'] as $warning) {
            $this->assertStringContainsString($warning, trans('modpacks-plus::strings.settings.curseforge_mirror_help'));
        }
    }
}
