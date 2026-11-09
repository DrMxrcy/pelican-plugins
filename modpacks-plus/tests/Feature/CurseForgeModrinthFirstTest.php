<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\ModrinthMatcher;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 31 step 4: CurseForge files are looked up on Modrinth by sha1. Matches download from
 * cdn.modrinth.com checked against Modrinth's sha512 (also when CurseForge blocks them),
 * Modrinth's server_side drops client-only mods, unknown files stay in.
 */
class CurseForgeModrinthFirstTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    /**
     * Modrinth's copies, by CurseForge letter: [project id, server_side].
     *
     * @var array<string, array{string, string}>
     */
    private array $onModrinth = [];

    /** @var array<string, array<string, mixed>> CurseForge file payloads by letter */
    private array $cfFiles = [];

    /** Modrinth answers 503 to everything. */
    private bool $modrinthDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        config()->set('modpacks-plus.curseforge.api_key', CurseForge::KEY);
    }

    private function contents(string $letter): string
    {
        return $letter.'-bytes';
    }

    private function modrinthUrl(string $letter): string
    {
        return 'https://cdn.modrinth.com/data/PROJ'.$letter.'/versions/VER'.$letter.'/'.strtolower($letter).'-mod.jar';
    }

    /**
     * Modrinth's version answer for a CurseForge letter.
     *
     * @return array<string, mixed>
     */
    private function modrinthVersion(string $letter, ?string $url = null, ?int $size = null): array
    {
        $body = $this->contents($letter);

        return [
            'id' => 'VER'.$letter,
            'project_id' => 'PROJ'.$letter,
            'version_number' => '1.0.'.$letter,
            'files' => [[
                'url' => $url ?? $this->modrinthUrl($letter),
                'filename' => strtolower($letter).'-mod.jar',
                'primary' => true,
                'size' => $size ?? strlen($body),
                'hashes' => ['sha1' => sha1($body), 'sha512' => hash('sha512', $body)],
            ]],
        ];
    }

    /**
     * A pack of five mods A–E plus a blocked mod F that Modrinth also lacks. B is blocked on
     * CurseForge (allowModDistribution false), M is a minimap Modrinth marks client-only.
     *
     * @param  array<string, array{string, string}>  $onModrinth
     * @param  array<string, mixed>  $versionOverrides  letter => version answer (replaces the default)
     */
    private function fakePack(array $onModrinth, array $versionOverrides = []): void
    {
        $this->onModrinth = $onModrinth;
        $mods = [];
        $entries = [];
        $id = 5000001;
        foreach (['A' => true, 'B' => false, 'C' => true, 'D' => true, 'E' => true, 'F' => false, 'M' => true] as $letter => $distributable) {
            $name = strtolower($letter).'-mod.jar';
            $this->cfFiles[$letter] = CurseForge::file($id, 200 + $id % 100, $name, $this->contents($letter), $distributable ? [] : ['downloadUrl' => null]);
            $mods[] = CurseForge::modEntry(200 + $id % 100, $letter.' Mod', $distributable);
            $entries[] = [200 + $id % 100, $id];
            $id++;
        }

        $zip = Mrpack::zip(['manifest.json' => CurseForge::manifest($entries)]);
        $pack = CurseForge::file(CurseForge::FILE_ID, CurseForge::PACK_ID, CurseForge::PACK_FILE_NAME, $zip);

        $stubs = [
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/'.CurseForge::FILE_ID => Http::response(CurseForge::data($pack)),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
            CurseForge::API.'mods/files' => Http::response(CurseForge::data(array_values($this->cfFiles))),
            CurseForge::API.'mods' => Http::response(CurseForge::data($mods)),
            // A fresh response per request: a stub's body stream can only be read once.
            CurseForge::url(CurseForge::FILE_ID, CurseForge::PACK_FILE_NAME) => fn () => Http::response($zip),
            'api.modrinth.com/*' => fn (Request $request) => $this->modrinth($request, $versionOverrides),
        ];
        foreach ($this->cfFiles as $letter => $file) {
            $body = $this->contents($letter);
            $stubs[CurseForge::url($file['id'], $file['fileName'])] = fn () => Http::response($body);
            // The node "downloads" Modrinth CDN files from here.
            $this->files->remote[$this->modrinthUrl($letter)] = $this->contents($letter);
        }
        Http::fake(array_merge($stubs, ['*' => Http::response('unexpected', 500)]));
    }

    /** @param  array<string, mixed>  $versionOverrides */
    private function modrinth(Request $request, array $versionOverrides): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($this->modrinthDown) {
            return Http::response('down', 503);
        }

        if ($path === '/v2/version_files') {
            $algorithm = $request['algorithm'];
            $answer = [];
            foreach ($this->onModrinth as $letter => $_) {
                $hash = $algorithm === 'sha1' ? sha1($this->contents($letter)) : hash('sha512', $this->contents($letter));
                if (in_array($hash, $request['hashes'], true)) {
                    $answer[$hash] = $versionOverrides[$letter] ?? $this->modrinthVersion($letter);
                }
            }

            return Http::response($answer === [] ? '{}' : $answer, 200, ['Content-Type' => 'application/json']);
        }

        if ($path === '/v2/projects') {
            $ids = json_decode((string) ($request->data()['ids'] ?? '[]'), true);
            $projects = [];
            foreach ($this->onModrinth as $letter => [$projectId, $side]) {
                if (in_array($projectId, $ids, true)) {
                    $projects[] = ['id' => $projectId, 'slug' => strtolower($letter).'-mod', 'title' => $letter.' Mod', 'server_side' => $side];
                }
            }

            return Http::response($projects);
        }

        return Http::response('unexpected', 500);
    }

    private function resolve(): InstallPlan
    {
        $caps = new Caps();
        $provider = new CurseForgePackProvider(new CurseForgeClient(CurseForge::KEY), new ModrinthPackProvider(new ModrinthClient(), $caps), new ModrinthMatcher(new ModrinthClient()), $caps);

        return $provider->resolvePlan((string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, new PlanValidator($caps, ['world'], PlanValidator::hostsFor('curseforge')), sys_get_temp_dir().'/mp-cf-mr-'.bin2hex(random_bytes(4)));
    }

    /** @return array<string, PlanFile> */
    private static function byPath(InstallPlan $plan): array
    {
        $files = [];
        foreach ($plan->files as $file) {
            $files[$file->path] = $file;
        }

        return $files;
    }

    public function test_three_of_five_files_come_from_modrinth_checked_by_sha512(): void
    {
        // A, B (blocked on CurseForge) and C are on Modrinth; D and E are not; F is blocked and not on Modrinth.
        $this->fakePack(['A' => ['PROJA', 'required'], 'B' => ['PROJB', 'optional'], 'C' => ['PROJC', 'unknown'], 'M' => ['PROJM', 'unsupported']]);

        $plan = $this->resolve();
        $files = self::byPath($plan);

        $this->assertSame(['mods/a-mod.jar', 'mods/b-mod.jar', 'mods/c-mod.jar', 'mods/d-mod.jar', 'mods/e-mod.jar'], array_keys($files));
        foreach (['A', 'B', 'C'] as $letter) {
            $file = $files['mods/'.strtolower($letter).'-mod.jar'];
            $this->assertSame($this->modrinthUrl($letter), $file->url, $letter);
            $this->assertSame(hash('sha512', $this->contents($letter)), $file->hashes['sha512'] ?? null, $letter);
            $this->assertSame(sha1($this->contents($letter)), $file->hashes['sha1'] ?? null, $letter);
            // Vouched for by the item-30 sha512 lookup at that exact URL: the only kind the node pulls.
            $this->assertSame(PlanFile::VERIFY_MODRINTH, $file->verify, $letter);
        }
        foreach (['D', 'E'] as $letter) {
            $file = $files['mods/'.strtolower($letter).'-mod.jar'];
            $this->assertSame('edge.forgecdn.net', parse_url((string) $file->url, PHP_URL_HOST), $letter);
            $this->assertSame(PlanFile::VERIFY_PANEL, $file->verify, $letter);
        }

        // Client-only on Modrinth: dropped. Blocked and not on Modrinth: listed.
        $this->assertSame(['mods/m-mod.jar'], $plan->skippedClientFiles);
        $this->assertSame(['f-mod'], array_column($plan->manualFiles, 'name'));
        $this->assertSame(['modrinth' => 3, 'curseforge' => 2, 'manual' => 1], InstallEngine::sources($plan));

        // One batched sha1 lookup with every CurseForge hash, one sha512 lookup for the swapped files.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/version_files') && $request['algorithm'] === 'sha1' && count($request['hashes']) === 7);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/version_files') && $request['algorithm'] === 'sha512' && count($request['hashes']) === 3);
        // Nothing was fetched from CurseForge for the blocked file, nor for the swapped ones.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'forgecdn') && preg_match('#/(b|f|a|c)-mod\.jar$#', $request->url()));
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.curseforge.com') {
                $this->assertSame([], $request->header('x-api-key'), $request->url());
            }
        }
    }

    public function test_a_file_with_an_unknown_side_stays_in(): void
    {
        $this->fakePack(['M' => ['PROJM', 'unsupported'], 'A' => ['PROJA', 'surprise']]);

        $plan = $this->resolve();

        $this->assertArrayHasKey('mods/a-mod.jar', self::byPath($plan));
        $this->assertSame('unknown', self::byPath($plan)['mods/a-mod.jar']->side);
        $this->assertArrayHasKey('mods/d-mod.jar', self::byPath($plan));
        $this->assertSame(['mods/m-mod.jar'], $plan->skippedClientFiles);
    }

    public function test_answers_that_are_not_about_the_exact_file_are_ignored(): void
    {
        $this->fakePack(['A' => ['PROJA', 'unsupported'], 'B' => ['PROJB', 'required'], 'C' => ['PROJC', 'required'], 'D' => ['PROJD', 'required']], [
            // Another file's hashes under A's sha1.
            'A' => ['id' => 'VERA', 'project_id' => 'PROJA', 'files' => [['url' => 'https://cdn.modrinth.com/data/PROJA/versions/VERA/x.jar', 'size' => 7, 'hashes' => ['sha1' => sha1('other'), 'sha512' => hash('sha512', 'other')]]]],
            // Another host.
            'B' => $this->modrinthVersion('B', 'https://evil.example/b-mod.jar'),
            // Another size.
            'C' => $this->modrinthVersion('C', null, 999),
            // A hostile project id.
            'D' => ['project_id' => '../x'] + $this->modrinthVersion('D'),
        ]);

        $plan = $this->resolve();
        $files = self::byPath($plan);

        $this->assertArrayHasKey('mods/a-mod.jar', $files, 'an answer about another file says nothing about the side');
        $this->assertSame('edge.forgecdn.net', parse_url((string) $files['mods/a-mod.jar']->url, PHP_URL_HOST));
        $this->assertSame('edge.forgecdn.net', parse_url((string) $files['mods/c-mod.jar']->url, PHP_URL_HOST));
        $this->assertSame('edge.forgecdn.net', parse_url((string) $files['mods/d-mod.jar']->url, PHP_URL_HOST));
        $this->assertArrayNotHasKey('mods/b-mod.jar', $files);
        $this->assertContains('b-mod', array_column($plan->manualFiles, 'name'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_a_failed_modrinth_lookup_stops_the_install(): void
    {
        $this->modrinthDown = true;
        $this->fakePack(['A' => ['PROJA', 'required']]);

        try {
            $this->resolve();
            $this->fail('Expected PackUnavailableException');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('api_unavailable', $exception->reason);
        }
    }

    public function test_misses_are_not_cached_for_oversized_lookups(): void
    {
        Http::fake(['api.modrinth.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);
        $hashes = array_map(fn (int $i) => sha1((string) $i), range(1, 1001));

        (new ModrinthMatcher(new ModrinthClient()))->match($hashes);
        $this->assertFalse(Cache::has('modpacks-plus:modrinth-sha1:'.$hashes[0]));

        (new ModrinthMatcher(new ModrinthClient()))->match([$hashes[0]]);
        $this->assertTrue(Cache::has('modpacks-plus:modrinth-sha1:'.$hashes[0]));
    }

    public function test_lookups_are_cached_per_hash(): void
    {
        $this->fakePack(['A' => ['PROJA', 'required']]);

        $this->resolve();
        $this->resolve();

        $sha1Lookups = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v2/version_files') && $request['algorithm'] === 'sha1');
        $this->assertCount(1, $sha1Lookups);
    }

    public function test_installs_with_modrinth_files_pulled_by_the_node_and_hands_them_to_modrinth_plus(): void
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n"]);
        $this->fakePack(['A' => ['PROJA', 'required'], 'B' => ['PROJB', 'required'], 'C' => ['PROJC', 'required'], 'M' => ['PROJM', 'unsupported']]);

        app(InstallEngine::class)->start($server, null, CurseForgePackProvider::ID, (string) CurseForge::PACK_ID, (string) CurseForge::FILE_ID, 'Test CF Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));
        $install = $this->latestInstall();

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $this->assertSame($this->contents($letter), $this->files->files['mods/'.strtolower($letter).'-mod.jar'] ?? null, $letter);
        }
        $this->assertArrayNotHasKey('mods/m-mod.jar', $this->files->files);
        $this->assertArrayNotHasKey('mods/f-mod.jar', $this->files->files);

        // The node only pulled the cdn.modrinth.com files.
        $this->assertEqualsCanonicalizing([$this->modrinthUrl('A'), $this->modrinthUrl('B'), $this->modrinthUrl('C')], $this->files->callsOf('pull'));

        $summary = $install->summary ?? [];
        $this->assertSame(['modrinth' => 3, 'curseforge' => 2, 'manual' => 1], $summary['sources'] ?? null);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.sources', ['modrinth' => 3, 'curseforge' => 2, 'manual' => 1])), (string) InstallProgress::summary($install));

        // The client-only mod is named with the Modrinth project that decided it.
        $this->assertSame([['path' => 'mods/m-mod.jar', 'project' => 'm-mod']], $summary['client_only_by'] ?? null);
        $this->assertStringContainsString('mods/m-mod.jar (m-mod)', (string) InstallProgress::summary($install));

        // Plan 33: the Modrinth matches are recorded for Modrinth Plus.
        $this->assertSame('written', $summary['modrinth_metadata']['status'] ?? null, (string) json_encode($summary['modrinth_metadata'] ?? null));
        $metadata = json_decode($this->files->files[ModrinthMetadataWriter::PATH] ?? '[]', true);
        $this->assertIsArray($metadata);
        $this->assertStringContainsString('PROJA', (string) json_encode($metadata));
        $this->assertStringContainsString('"provider": "curseforge"', (string) $this->files->files[ModrinthMetadataWriter::PATH]);
    }
}
