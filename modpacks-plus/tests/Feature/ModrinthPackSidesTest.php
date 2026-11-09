<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 30 fix: Modrinth packs without env markers (Fabulously Optimized 15.0.0-beta.3)
 * installed Sodium, EMF/ETF, Animatica and resource packs on the server. A file the pack
 * doesn't mark is decided by its Modrinth project's server_side (looked up by sha512);
 * an explicit env.server always wins; files[] entries under resourcepacks/ and shaderpacks/
 * are never written; every skipped file is listed in the summary with its reason.
 */
class ModrinthPackSidesTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->workDir = sys_get_temp_dir().'/mp-sides-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->workDir.'/pack.mrpack');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    /**
     * An index entry without any env block (or with env but no env.server).
     *
     * @param  array<string, string>|null  $env
     * @return array<string, mixed>
     */
    private static function unmarked(string $path, string $contents, ?array $env = null, ?string $url = null): array
    {
        $file = Mrpack::file($path, $contents, $url ?? 'https://cdn.modrinth.com/data/X/versions/v/'.basename($path));
        unset($file['env']);
        if ($env !== null) {
            $file['env'] = $env;
        }

        return $file;
    }

    /**
     * Fakes the version, the archive, version_files (sha512 => project) and /projects.
     *
     * @param  array<int, array<string, mixed>>  $indexFiles
     * @param  array<string, string>  $projectOf  path => Modrinth project id (files not listed are not on Modrinth)
     * @param  array<string, string>  $sideOf  project id => server_side
     */
    private function resolve(array $indexFiles, array $projectOf, array $sideOf, bool $lookupFails = false): InstallPlan
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index($indexFiles)]);

        $lookup = [];
        foreach ($indexFiles as $file) {
            if (isset($projectOf[$file['path']])) {
                $lookup[$file['hashes']['sha512']] = [
                    'id' => 'v'.substr($file['hashes']['sha1'], 0, 7),
                    'project_id' => $projectOf[$file['path']],
                    'files' => [['url' => $file['downloads'][0], 'hashes' => $file['hashes'], 'filename' => basename($file['path'])]],
                ];
            }
        }

        $projects = [];
        foreach ($sideOf as $id => $side) {
            $projects[] = ['id' => $id, 'slug' => strtolower($id).'-slug', 'server_side' => $side];
        }

        Http::fake([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => $lookupFails ? Http::response('down', 503) : Http::response($lookup === [] ? '{}' : $lookup, 200, ['Content-Type' => 'application/json']),
            'api.modrinth.com/v2/projects*' => Http::response($projects),
            Mrpack::ARCHIVE_URL => Http::response($archive),
            '*' => Http::response('unexpected', 500),
        ]);

        $provider = new ModrinthPackProvider(new ModrinthClient(), new Caps());

        return $provider->resolvePlan(Mrpack::PACK_ID, Mrpack::VERSION_ID, new PlanValidator(new Caps(), ['world']), $this->workDir);
    }

    /** @return array<int, string> */
    private static function paths(InstallPlan $plan): array
    {
        return array_map(fn (PlanFile $file) => $file->path, $plan->files);
    }

    public function test_unmarked_files_follow_the_modrinth_project_server_side(): void
    {
        $plan = $this->resolve([
            self::unmarked('mods/sodium.jar', 'sodium'),
            self::unmarked('mods/lithium.jar', 'lithium'),
            self::unmarked('mods/unknown.jar', 'unknown'),
            self::unmarked('mods/elsewhere.jar', 'elsewhere'),
            // env present but no env.server: same as no env.
            self::unmarked('mods/animatica.jar', 'animatica', ['client' => 'required']),
        ], [
            'mods/sodium.jar' => 'SODIUM01',
            'mods/lithium.jar' => 'LITHIUM1',
            'mods/unknown.jar' => 'UNKNOWN1',
            'mods/animatica.jar' => 'ANIMATIC',
        ], [
            'SODIUM01' => 'unsupported',
            'LITHIUM1' => 'required',
            'UNKNOWN1' => 'unknown',
            'ANIMATIC' => 'unsupported',
        ]);

        // Only server_side: unsupported is skipped; unknown and not-on-Modrinth stay.
        $this->assertSame(['mods/lithium.jar', 'mods/unknown.jar', 'mods/elsewhere.jar'], self::paths($plan));
        $this->assertSame(['mods/sodium.jar', 'mods/animatica.jar'], $plan->skippedClientFiles);
        $this->assertSame([
            ['path' => 'mods/sodium.jar', 'project' => 'sodium01-slug'],
            ['path' => 'mods/animatica.jar', 'project' => 'animatic-slug'],
        ], $plan->clientOnlyBy);

        // Looked up by sha512, the projects in one batch.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/version_files')
            && $request['algorithm'] === 'sha512' && in_array(hash('sha512', 'sodium'), $request['hashes'], true));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v2/projects?'));
    }

    public function test_an_explicit_env_server_beats_the_project_side(): void
    {
        $required = Mrpack::file('mods/sodium.jar', 'sodium', 'https://cdn.modrinth.com/data/X/versions/v/sodium.jar', 'required');

        $plan = $this->resolve([$required], ['mods/sodium.jar' => 'SODIUM01'], ['SODIUM01' => 'unsupported']);

        $this->assertSame(['mods/sodium.jar'], self::paths($plan));
        $this->assertSame([], $plan->skippedClientFiles);
        $this->assertSame([], $plan->clientOnlyBy);
        // Every file is marked, so no project lookup is needed.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/projects'));
    }

    public function test_resource_and_shader_pack_files_are_never_written(): void
    {
        $plan = $this->resolve([
            Mrpack::file('resourcepacks/Fresh.zip', 'fresh', 'https://cdn.modrinth.com/data/R/versions/r/Fresh.zip'),
            self::unmarked('shaderpacks/Complementary.zip', 'shader'),
            Mrpack::file('mods/lithium.jar', 'lithium', 'https://cdn.modrinth.com/data/L/versions/l/lithium.jar'),
            // Only the top-level folder counts.
            Mrpack::file('config/resourcepacks/keep.json', '{}', 'https://cdn.modrinth.com/data/C/versions/c/keep.json'),
        ], [], []);

        $this->assertSame(['mods/lithium.jar', 'config/resourcepacks/keep.json'], self::paths($plan));
        $this->assertSame(['resourcepacks/Fresh.zip', 'shaderpacks/Complementary.zip'], $plan->skippedClientFolderFiles);
        $this->assertEqualsCanonicalizing(['resourcepacks/Fresh.zip', 'shaderpacks/Complementary.zip'], $plan->skippedClientFiles);
        // A client-only folder file is never looked up.
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/version_files')
            && in_array(hash('sha512', 'shader'), (array) $request['hashes'], true));
    }

    public function test_a_failed_side_lookup_stops_the_install(): void
    {
        try {
            // Hosted on GitHub: only the side lookup asks Modrinth about it.
            $this->resolve([self::unmarked('mods/sodium.jar', 'sodium', url: 'https://github.com/o/r/releases/download/v1/sodium.jar')], [], [], lookupFails: true);
        } catch (PackUnavailableException $exception) {
            $this->assertSame('api_unavailable', $exception->reason);

            return;
        }

        $this->fail('Expected PackUnavailableException [api_unavailable]');
    }

    public function test_the_summary_lists_every_skipped_file_with_its_reason(): void
    {
        $plan = $this->resolve([
            self::unmarked('mods/sodium.jar', 'sodium'),
            Mrpack::file('mods/zoomify.jar', 'zoom', 'https://cdn.modrinth.com/data/Z/versions/z/zoomify.jar', 'unsupported'),
            Mrpack::file('resourcepacks/Fresh.zip', 'fresh', 'https://cdn.modrinth.com/data/R/versions/r/Fresh.zip'),
            self::unmarked('mods/lithium.jar', 'lithium'),
        ], ['mods/sodium.jar' => 'SODIUM01', 'mods/lithium.jar' => 'LITHIUM1'], ['SODIUM01' => 'unsupported', 'LITHIUM1' => 'required']);

        $install = new PackInstall();
        $install->plan = $plan->toArray();
        $summary = app(InstallEngine::class)->summary($install);

        $this->assertSame(3, $summary['skipped_client_files']);
        $this->assertSame([['path' => 'mods/sodium.jar', 'project' => 'sodium01-slug']], $summary['client_only_by']);
        $this->assertSame(['resourcepacks/Fresh.zip'], $summary['client_only_folder_files']);
        $this->assertSame(['mods/zoomify.jar'], $summary['client_only_pack_files']);

        $install->summary = $summary;
        $html = (string) InstallProgress::summary($install);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.skipped_client', ['count' => 3])), $html);
        $this->assertStringContainsString('mods/sodium.jar (sodium01-slug)', $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.client_only_folder_files', ['paths' => 'resourcepacks/Fresh.zip'])), $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.client_only_pack_files', ['paths' => 'mods/zoomify.jar'])), $html);
    }

    /** Plan 32 review N1: launcher-type files are skipped for every provider, so the line names no server pack. */
    public function test_the_skipped_launcher_files_line_is_provider_neutral(): void
    {
        foreach (['en', 'de'] as $locale) {
            $line = trans('modpacks-plus::strings.summary.skipped_pack_files', ['paths' => 'start.sh'], $locale);
            $this->assertStringNotContainsStringIgnoringCase('server-pack', $line, $locale);
            $this->assertStringNotContainsStringIgnoringCase('server pack', $line, $locale);
            $this->assertStringContainsString('start.sh', $line);
        }
    }
}
