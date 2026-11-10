<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 30 step 3: the Modrinth provider reads packs safely. It refuses bad archive
 * hosts and sizes before downloading, verifies the archive's sha512, checks every
 * archive entry (zip-slip) before reading the index, and decides per file how the
 * node's download will be verified.
 */
class ModrinthPackProviderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/mp-provider-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->workDir.'/pack.mrpack');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    private function provider(?Caps $caps = null): ModrinthPackProvider
    {
        return new ModrinthPackProvider(new ModrinthClient(), $caps ?? new Caps());
    }

    private function validator(?Caps $caps = null): PlanValidator
    {
        return new PlanValidator($caps ?? new Caps(), ['world']);
    }

    /**
     * Fakes the version endpoint, the archive download and the hash lookup.
     *
     * @param  array<string, mixed>|null  $version
     * @param  array<string, mixed>  $lookup
     */
    private function fakeModrinth(string $archive, ?array $version = null, array $lookup = []): void
    {
        Http::fake([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response($version ?? Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => Http::response($lookup === [] ? '{}' : $lookup, 200, ['Content-Type' => 'application/json']),
            Mrpack::ARCHIVE_URL => Http::response($archive),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    private function resolve(?Caps $caps = null): InstallPlan
    {
        return $this->provider($caps)->resolvePlan(Mrpack::PACK_ID, Mrpack::VERSION_ID, $this->validator($caps), $this->workDir);
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

    private function assertNoDownloadFrom(string $host): void
    {
        Http::assertNotSent(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === $host);
    }

    public function test_resolves_a_plan_from_an_mrpack(): void
    {
        $sodium = Mrpack::file('mods/sodium.jar', 'sodium', 'https://cdn.modrinth.com/data/AANobbMI/versions/x/sodium.jar');
        $github = Mrpack::file('mods/from-github.jar', 'gh', 'https://github.com/o/r/releases/download/v1/from-github.jar');
        $clientOnly = Mrpack::file('mods/zoomify.jar', 'zoom', 'https://cdn.modrinth.com/data/z/versions/y/zoomify.jar', 'unsupported');
        $files = [$sodium, $github, $clientOnly];

        $archive = Mrpack::zip([
            'modrinth.index.json' => Mrpack::index($files),
            'overrides/' => '',
            'overrides/config/a.toml' => 'client+server a',
            'overrides/config/b.toml' => 'b',
            'server-overrides/config/a.toml' => 'server a',
            'client-overrides/options.txt' => 'client only',
            'overrides/ops.json' => '[]',
        ]);
        $this->fakeModrinth($archive, lookup: Mrpack::lookup([$sodium]));

        $plan = $this->resolve();

        $this->assertSame('modrinth', $plan->provider);
        $this->assertSame('1.20.1', $plan->minecraftVersion);
        $this->assertSame('fabric', $plan->loader);
        $this->assertSame('0.15.11', $plan->loaderVersion);
        $this->assertSame('Test Pack', $plan->packName);

        $this->assertSame(['mods/sodium.jar', 'mods/from-github.jar'], array_map(fn (PlanFile $file) => $file->path, $plan->files));
        $this->assertSame(['mods/zoomify.jar'], $plan->skippedClientFiles);
        $this->assertSame(PlanFile::VERIFY_MODRINTH, $plan->files[0]->verify);
        $this->assertSame(PlanFile::VERIFY_PANEL, $plan->files[1]->verify);

        $overrides = [];
        foreach ($plan->overrides as $override) {
            $overrides[$override->path] = $override->entry;
        }
        ksort($overrides);
        $this->assertSame([
            'config/a.toml' => 'server-overrides/config/a.toml',
            'config/b.toml' => 'overrides/config/b.toml',
        ], $overrides);
        $this->assertSame(['ops.json'], $plan->skippedOverrides);

        // Only the cdn.modrinth.com file was looked up, by sha512.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/version_files')
            && $request['algorithm'] === 'sha512'
            && $request['hashes'] === [$sodium['hashes']['sha512']]);
    }

    public function test_a_lookup_hit_at_another_url_is_not_trusted(): void
    {
        $sodium = Mrpack::file('mods/sodium.jar', 'sodium', 'https://cdn.modrinth.com/data/AANobbMI/versions/x/sodium.jar');
        $lookup = Mrpack::lookup([$sodium]);
        $lookup[$sodium['hashes']['sha512']]['files'][0]['url'] = 'https://cdn.modrinth.com/data/OTHER/versions/x/other.jar';

        $this->fakeModrinth(Mrpack::zip(['modrinth.index.json' => Mrpack::index([$sodium])]), lookup: $lookup);

        $this->assertSame(PlanFile::VERIFY_PANEL, $this->resolve()->files[0]->verify);
    }

    public function test_a_failed_lookup_stops_the_plan(): void
    {
        $sodium = Mrpack::file('mods/sodium.jar', 'sodium', 'https://cdn.modrinth.com/data/AANobbMI/versions/x/sodium.jar');
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([$sodium])]);

        Http::fake([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => Http::response('down', 503),
            Mrpack::ARCHIVE_URL => Http::response($archive),
        ]);

        $this->expectException(PackUnavailableException::class);
        $this->resolve();
    }

    public function test_requests_send_the_plugin_user_agent_without_an_email(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $this->fakeModrinth($archive);

        $this->resolve();

        $userAgent = (new ModrinthClient())->userAgent();
        $this->assertStringStartsWith('DrMxrcy/modpacks-plus/', $userAgent);
        $this->assertStringNotContainsString('@', $userAgent);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.modrinth.com') && $request->hasHeader('User-Agent', $userAgent));
        Http::assertSent(fn (Request $request) => $request->url() === Mrpack::ARCHIVE_URL && $request->hasHeader('User-Agent', $userAgent));
    }

    public function test_zip_slip_entry_is_rejected_before_the_index_is_read(): void
    {
        $archive = Mrpack::zip([
            'modrinth.index.json' => Mrpack::index([]),
            'overrides/../evil' => 'pwned',
        ]);
        $this->fakeModrinth($archive);

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    public function test_hostile_client_override_entry_still_rejects_the_pack(): void
    {
        $this->fakeModrinth(Mrpack::zip([
            'modrinth.index.json' => Mrpack::index([]),
            'client-overrides/../../evil' => 'pwned',
        ]));

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    public function test_hostile_index_path_rejects_the_pack(): void
    {
        $evil = Mrpack::file('../../evil.jar', 'x', 'https://cdn.modrinth.com/data/x/versions/y/evil.jar');
        $this->fakeModrinth(Mrpack::zip(['modrinth.index.json' => Mrpack::index([$evil])]));

        $this->assertUnsafe(fn () => $this->resolve(), 'unsafe_path');
    }

    public function test_non_allowlisted_download_in_index_rejects_the_pack(): void
    {
        $evil = Mrpack::file('mods/a.jar', 'x', 'https://evil.example/a.jar');
        $this->fakeModrinth(Mrpack::zip(['modrinth.index.json' => Mrpack::index([$evil])]));

        $this->assertUnsafe(fn () => $this->resolve(), 'no_allowed_url');
    }

    public function test_archive_hash_mismatch_is_rejected_and_the_file_removed(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $version = Mrpack::version($archive);
        $version['files'][0]['hashes']['sha512'] = hash('sha512', 'something else');
        $this->fakeModrinth($archive, $version);

        $this->assertUnsafe(fn () => $this->resolve(), 'hash_mismatch');
        $this->assertFileDoesNotExist($this->workDir.'/pack.mrpack');
    }

    public function test_archive_over_the_cap_is_never_downloaded(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $this->fakeModrinth($archive);

        $this->assertUnsafe(fn () => $this->resolve(new Caps(maxArchiveBytes: strlen($archive) - 1)), 'archive_too_large');
        $this->assertNoDownloadFrom('cdn.modrinth.com');
    }

    public function test_archive_larger_than_declared_is_rejected(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        // Modrinth says 10 bytes; the CDN sends more.
        $this->fakeModrinth($archive, Mrpack::version($archive, size: 10));

        $this->assertUnsafe(fn () => $this->resolve(), 'archive_too_large');
        $this->assertFileDoesNotExist($this->workDir.'/pack.mrpack');
    }

    public function test_archive_on_a_non_allowlisted_host_is_never_downloaded(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $this->fakeModrinth($archive, Mrpack::version($archive, 'https://evil.example/pack.mrpack'));

        $this->assertUnsafe(fn () => $this->resolve(), 'no_allowed_url');
        $this->assertNoDownloadFrom('evil.example');
    }

    public function test_too_many_archive_entries_is_rejected(): void
    {
        $this->fakeModrinth(Mrpack::zip([
            'modrinth.index.json' => Mrpack::index([]),
            'overrides/a' => 'a',
            'overrides/b' => 'b',
        ]));

        $this->assertUnsafe(fn () => $this->resolve(new Caps(maxArchiveEntries: 2)), 'too_many_entries');
    }

    public function test_override_bigger_than_the_file_cap_is_rejected(): void
    {
        $this->fakeModrinth(Mrpack::zip([
            'modrinth.index.json' => Mrpack::index([]),
            'overrides/big.bin' => str_repeat('x', 2000),
        ]));

        $this->assertUnsafe(fn () => $this->resolve(new Caps(maxFileBytes: 1999)), 'file_too_large');
    }

    public function test_version_of_another_project_is_rejected(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $this->fakeModrinth($archive, Mrpack::version($archive, projectId: 'SOMEOTHR'));

        $this->expectException(PackUnavailableException::class);
        $this->resolve();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function malformedPacks(): array
    {
        return [
            'no index' => [['overrides/a' => 'a']],
            'not json' => [['modrinth.index.json' => 'not json']],
            'unknown format version' => [['modrinth.index.json' => (string) json_encode(['formatVersion' => 2, 'game' => 'minecraft', 'files' => [], 'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '1']])]],
            'no loader' => [['modrinth.index.json' => Mrpack::index([], ['minecraft' => '1.20.1'])]],
            'two loaders' => [['modrinth.index.json' => Mrpack::index([], ['minecraft' => '1.20.1', 'fabric-loader' => '1', 'forge' => '2'])]],
        ];
    }

    /** @param  array<string, string>  $entries */
    #[DataProvider('malformedPacks')]
    public function test_missing_or_malformed_index_is_rejected(array $entries): void
    {
        $this->fakeModrinth(Mrpack::zip($entries));

        try {
            $this->resolve();
            $this->fail('Expected a malformed pack to be rejected');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('bad_index', $exception->reason);
        }
    }

    public function test_redirect_guard_refuses_non_allowlisted_hops(): void
    {
        $guard = ModrinthClient::redirectGuard($this->validator());
        $request = new PsrRequest('GET', 'https://github.com/o/r/releases/download/v1/a.jar');
        $response = new PsrResponse(302);

        $guard($request, $response, new Uri('https://objects.githubusercontent.com/release/1?x=1'));

        foreach (['https://evil.example/a.jar', 'http://objects.githubusercontent.com/a', 'https://169.254.169.254/latest/meta-data'] as $hop) {
            try {
                $guard($request, $response, new Uri($hop));
                $this->fail("Redirect to $hop was allowed");
            } catch (UnsafePlanException $exception) {
                $this->assertSame('no_allowed_url', $exception->reason);
            }
        }
    }

    public function test_search_filters_by_server_version_loader_and_categories(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response([
            'hits' => [
                ['project_id' => 'AAAAAAAA', 'slug' => 'fo', 'title' => 'Fabulously Optimized', 'icon_url' => 'javascript:alert(1)', 'downloads' => 3],
                ['project_id' => '../bad', 'title' => 'Bad id'],
            ],
            'total_hits' => 2,
        ])]);

        $result = $this->provider()->search('optimized', '1.20.1', 'fabric', ['optimization'], 'downloads', 2);

        $this->assertCount(1, $result['hits']);
        $this->assertSame('AAAAAAAA', $result['hits'][0]['id']);
        $this->assertNull($result['hits'][0]['icon_url']);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['index'] === 'downloads'
                && $query['offset'] === '20'
                && json_decode($query['facets'], true) === [['project_type:modpack'], ['versions:1.20.1'], ['categories:fabric'], ['categories:optimization']];
        });
    }

    public function test_search_without_a_version_or_loader_sends_only_the_modpack_facet_and_a_bounded_query(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0])]);

        $this->provider()->search(' '.str_repeat('p', 150).' ', null, null, [], 'bogus', 1, 500);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return json_decode($query['facets'], true) === [['project_type:modpack']]
                && $query['query'] === str_repeat('p', 100) && $query['index'] === 'relevance' && $query['limit'] === '100';
        });
    }

    public function test_versions_are_normalised_with_their_mrpack(): void
    {
        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index([])]);
        $old = Mrpack::version($archive);
        $old['id'] = 'OLDVER01';
        $old['date_published'] = '2025-01-01T00:00:00Z';
        Http::fake(['api.modrinth.com/v2/project/'.Mrpack::PACK_ID.'/version' => Http::response([$old, Mrpack::version($archive)])]);

        $versions = $this->provider()->versions(Mrpack::PACK_ID);

        $this->assertSame([Mrpack::VERSION_ID, 'OLDVER01'], array_column($versions, 'id'));
        $this->assertSame(Mrpack::ARCHIVE_URL, $versions[0]['file']['url'] ?? null);
    }

    public function test_ids_are_validated_before_any_request(): void
    {
        Http::fake();

        $this->expectException(PackUnavailableException::class);

        try {
            $this->provider()->versions('../../user/x');
        } finally {
            Http::assertNothingSent();
        }
    }
}
