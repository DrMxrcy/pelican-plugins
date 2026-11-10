<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\ModrinthMatcher;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 31 step 2: CurseForge search, detail and versions mapped to the provider contract.
 * Every id, link and download URL from the API is checked before use.
 */
class CurseForgePackProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function provider(): CurseForgePackProvider
    {
        return new CurseForgePackProvider(new CurseForgeClient(CurseForge::KEY), new ModrinthPackProvider(new ModrinthClient(), new Caps()), new ModrinthMatcher(new ModrinthClient()), new Caps());
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

    /** Every request went to the CurseForge API with the key. */
    private function assertOnlyCurseForgeApi(): void
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $this->assertStringStartsWith('https://api.curseforge.com/v1/', $request->url());
        }
    }

    public function test_search_sends_the_filters_and_maps_hits(): void
    {
        Http::fake([
            CurseForge::API.'categories*' => Http::response(CurseForge::data([
                ['id' => 4472, 'name' => 'Tech', 'classId' => 4471, 'isClass' => false],
                ['id' => 4471, 'name' => 'Modpacks', 'classId' => 4471, 'isClass' => true],
                ['id' => 9, 'name' => 'Other class', 'classId' => 6],
            ])),
            CurseForge::API.'mods/search*' => Http::response([
                'data' => [
                    CurseForge::mod(),
                    CurseForge::mod(7, ['classId' => 6]),
                    CurseForge::mod(0),
                    ['id' => '1 OR 1', 'classId' => 4471],
                ],
                'pagination' => ['index' => 20, 'pageSize' => 20, 'resultCount' => 1, 'totalCount' => 999999],
            ]),
        ]);

        $result = $this->provider()->search(' all the mods ', '1.20.1', 'forge', ['Tech', 'Unknown'], 'downloads', 2, 20);

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/mods/search')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['gameId'] === '432' && $query['classId'] === '4471' && $query['searchFilter'] === 'all the mods'
                && $query['gameVersion'] === '1.20.1' && $query['modLoaderType'] === '1' && $query['categoryIds'] === '[4472]'
                && $query['sortField'] === '6' && $query['sortOrder'] === 'desc' && $query['index'] === '20' && $query['pageSize'] === '20';
        });
        $this->assertOnlyCurseForgeApi();

        $this->assertSame(10000, $result['total']);
        $this->assertCount(1, $result['hits']);
        $hit = $result['hits'][0];
        $this->assertSame((string) CurseForge::PACK_ID, $hit['id']);
        $this->assertSame('Test CF Pack', $hit['title']);
        $this->assertSame('Author', $hit['author']);
        $this->assertSame(12345, $hit['downloads']);
        $this->assertSame(['Tech'], $hit['categories']);
        $this->assertSame(['1.20.1'], $hit['game_versions']);
        $this->assertSame('https://media.forgecdn.net/avatars/thumbnails/1/2/64/64/logo.png', $hit['icon_url']);
    }

    public function test_search_without_a_version_or_loader_sends_neither_and_a_bounded_query(): void
    {
        Http::fake([CurseForge::API.'mods/search*' => Http::response(['data' => [], 'pagination' => ['totalCount' => 0]])]);

        $this->provider()->search(str_repeat('x', 150), null, null, [], null, 1, 500);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return !isset($query['gameVersion']) && !isset($query['modLoaderType'])
                && $query['searchFilter'] === str_repeat('x', 100) && (int) $query['pageSize'] <= 50 && $query['classId'] === '4471';
        });
        $this->assertOnlyCurseForgeApi();
    }

    public function test_search_never_loops_over_more_hits_than_asked_for(): void
    {
        $hits = [];
        for ($id = 1; $id <= 200; $id++) {
            $hits[] = CurseForge::mod($id);
        }
        Http::fake([CurseForge::API.'mods/search*' => Http::response(['data' => $hits, 'pagination' => ['totalCount' => 200]])]);

        $this->assertCount(20, $this->provider()->search(null, null, null, [], null, 1, 20)['hits']);
    }

    public function test_cached_details_are_kept_apart_per_mode(): void
    {
        Http::fake([
            'api.curse.tools/v1/mods/'.CurseForge::PACK_ID.'/description' => Http::response(CurseForge::data('mirror')),
            'api.curse.tools/v1/mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod(overrides: ['name' => 'From the mirror']))),
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/description' => Http::response(CurseForge::data('api')),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod(overrides: ['name' => 'From the API']))),
        ]);
        $modrinth = new ModrinthPackProvider(new ModrinthClient(), new Caps());
        $mirror = new CurseForgePackProvider(new CurseForgeClient(null, true), $modrinth, new ModrinthMatcher(new ModrinthClient()), new Caps());

        $this->assertSame('From the mirror', $mirror->project((string) CurseForge::PACK_ID)['title']);
        // Once a key is set, nothing read through the mirror is reused.
        $this->assertSame('From the API', $this->provider()->project((string) CurseForge::PACK_ID)['title']);
    }

    public function test_search_past_the_curseforge_window_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame([], $this->provider()->search(null, null, null, [], null, 600, 20)['hits']);

        Http::assertNothingSent();
    }

    public function test_categories_failures_are_not_cached(): void
    {
        Http::fakeSequence(CurseForge::API.'categories*')
            ->push('down', 503)
            ->push(CurseForge::data([['id' => 4472, 'name' => 'Tech', 'classId' => 4471]]));

        $first = $this->provider()->categories();
        $second = $this->provider()->categories();
        $third = $this->provider()->categories();

        $this->assertSame([[], ['Tech'], ['Tech']], [$first, $second, $third]);
        Http::assertSentCount(2);
    }

    public function test_project_maps_details_and_strips_html(): void
    {
        Http::fake([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/description' => Http::response(CurseForge::data('<p>Hello <b>world</b></p><script>alert(1)</script><a href="javascript:x">link</a>&amp;more')),
            CurseForge::API.'mods/'.CurseForge::PACK_ID => Http::response(CurseForge::data(CurseForge::mod())),
        ]);

        $project = $this->provider()->project((string) CurseForge::PACK_ID);

        $this->assertSame('Test CF Pack', $project['title']);
        $this->assertStringNotContainsString('<', $project['body']);
        $this->assertStringNotContainsString('alert', $project['body']);
        $this->assertStringContainsString('Hello world', $project['body']);
        $this->assertStringContainsString('&more', $project['body']);
        $this->assertSame(['https://media.forgecdn.net/attachments/1/2/shot.png'], array_column($project['gallery'], 'url'));
        $this->assertSame(['forge'], $project['loaders']);
        $this->assertSame('https://www.curseforge.com/minecraft/modpacks/test-cf-pack', $project['website_url']);
        $this->assertOnlyCurseForgeApi();
    }

    public function test_project_refuses_something_that_is_not_a_modpack(): void
    {
        Http::fake([CurseForge::API.'mods/42' => Http::response(CurseForge::data(CurseForge::modEntry(42, 'A Mod')))]);

        $this->assertSame('not_a_modpack', $this->catchUnavailable(fn () => $this->provider()->project('42'))->reason);
    }

    public function test_versions_list_client_packs_newest_first(): void
    {
        $newer = CurseForge::file(4000009, CurseForge::PACK_ID, 'Pack-1.1.zip', 'b', ['fileDate' => '2026-10-09T00:00:00Z', 'serverPackFileId' => 4000010]);
        $older = CurseForge::file(4000001, CurseForge::PACK_ID, 'Pack 1.0+x.zip', 'a', ['fileDate' => '2026-10-01T00:00:00Z', 'releaseType' => 2]);
        $server = CurseForge::file(4000010, CurseForge::PACK_ID, 'Server.zip', 'c', ['isServerPack' => true]);
        $foreign = CurseForge::file(4000011, 999, 'Other.zip', 'd');
        $blocked = CurseForge::file(4000012, CurseForge::PACK_ID, 'Blocked.zip', 'e', ['downloadUrl' => null, 'fileDate' => '2026-09-01T00:00:00Z']);

        Http::fake([CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files*' => Http::response(CurseForge::data([$older, $server, $newer, $foreign, $blocked]))]);

        $versions = $this->provider()->versions((string) CurseForge::PACK_ID);

        $this->assertSame(['4000009', '4000001', '4000012'], array_column($versions, 'id'));
        $this->assertSame((string) CurseForge::PACK_ID, $versions[0]['project_id']);
        $this->assertSame(['1.20.1'], $versions[0]['game_versions']);
        $this->assertSame(['forge'], $versions[0]['loaders']);
        $this->assertSame('beta', $versions[1]['version_type']);
        $file = $versions[1]['file'];
        $this->assertNotNull($file);
        $this->assertSame('https://edge.forgecdn.net/files/4000/1/Pack%201.0%2Bx.zip', $file['url']);
        $this->assertSame(['sha1' => sha1('a')], $file['hashes']);
        $this->assertNull($versions[2]['file']);
    }

    public function test_pack_version_must_belong_to_the_pack_and_not_be_a_server_pack(): void
    {
        Http::fake([
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/4000001' => Http::response(CurseForge::data(CurseForge::file(4000001, 999, 'x.zip', 'x'))),
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/4000002' => Http::response(CurseForge::data(CurseForge::file(4000002, CurseForge::PACK_ID, 'x.zip', 'x', ['isServerPack' => true]))),
            CurseForge::API.'mods/'.CurseForge::PACK_ID.'/files/4000003' => Http::response(CurseForge::data(CurseForge::file(4000099, CurseForge::PACK_ID, 'x.zip', 'x'))),
        ]);

        $this->assertSame('version_mismatch', $this->catchUnavailable(fn () => $this->provider()->packVersion((string) CurseForge::PACK_ID, '4000001'))->reason);
        $this->assertSame('curseforge_not_found', $this->catchUnavailable(fn () => $this->provider()->packVersion((string) CurseForge::PACK_ID, '4000002'))->reason);
        $this->assertSame('curseforge_not_found', $this->catchUnavailable(fn () => $this->provider()->packVersion((string) CurseForge::PACK_ID, '4000003'))->reason);
    }

    /** @return array<string, array{string}> */
    public static function hostileIds(): array
    {
        return [
            'traversal' => ['1/../2'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'letters' => ['abc'],
            'too big' => ['99999999999'],
            'url' => ['https://evil.example'],
            'space' => ['1 2'],
        ];
    }

    #[DataProvider('hostileIds')]
    public function test_hostile_ids_never_reach_the_api(string $id): void
    {
        Http::fake();

        $this->assertSame('curseforge_not_found', $this->catchUnavailable(fn () => $this->provider()->project($id))->reason);
        $this->assertSame('curseforge_not_found', $this->catchUnavailable(fn () => $this->provider()->versions($id))->reason);
        $this->assertSame('curseforge_not_found', $this->catchUnavailable(fn () => $this->provider()->packVersion((string) CurseForge::PACK_ID, $id))->reason);

        Http::assertNothingSent();
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function downloadUrls(): array
    {
        return [
            'plain' => ['https://edge.forgecdn.net/files/4712/866/jei-1.20.1.jar', 'https://edge.forgecdn.net/files/4712/866/jei-1.20.1.jar'],
            'mediafilez' => ['https://mediafilez.forgecdn.net/files/4712/6/a.jar', 'https://mediafilez.forgecdn.net/files/4712/6/a.jar'],
            'space and plus re-encoded' => ['https://edge.forgecdn.net/files/1/2/A b+c.jar', 'https://edge.forgecdn.net/files/1/2/A%20b%2Bc.jar'],
            'already encoded' => ['https://edge.forgecdn.net/files/1/2/A%20b.jar', 'https://edge.forgecdn.net/files/1/2/A%20b.jar'],
            'http' => ['http://edge.forgecdn.net/files/1/2/a.jar', null],
            'other host' => ['https://evil.example/files/1/2/a.jar', null],
            'look-alike host' => ['https://edge.forgecdn.net.evil.example/files/1/2/a.jar', null],
            'userinfo' => ['https://edge.forgecdn.net@evil.example/files/1/2/a.jar', null],
            'port' => ['https://edge.forgecdn.net:8443/files/1/2/a.jar', null],
            'query' => ['https://edge.forgecdn.net/files/1/2/a.jar?x=1', null],
            'fragment' => ['https://edge.forgecdn.net/files/1/2/a.jar#x', null],
            'other path' => ['https://edge.forgecdn.net/other/1/2/a.jar', null],
            'encoded slash' => ['https://edge.forgecdn.net/files/1/2/..%2F..%2Fx.jar', null],
            'encoded parent' => ['https://edge.forgecdn.net/files/1/2/%2e%2e', null],
            'dot file' => ['https://edge.forgecdn.net/files/1/2/.hidden', null],
            'control char' => ['https://edge.forgecdn.net/files/1/2/a%0a.jar', null],
            'not a string' => [['x'], null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('downloadUrls')]
    public function test_download_urls_are_rebuilt_or_refused(mixed $url, ?string $expected): void
    {
        $this->assertSame($expected, CurseForgePackProvider::downloadUrl($url));
    }

    public function test_file_download_urls_are_built_from_id_and_name(): void
    {
        $this->assertSame('https://edge.forgecdn.net/files/5000/1/a.jar', CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', 'https://edge.forgecdn.net/files/5000/1/a.jar'));
        $this->assertSame('https://edge.forgecdn.net/files/5000/1/a.jar', CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', 'https://mediafilez.forgecdn.net/files/5000/1/a.jar'));
        $this->assertNull(CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', 'https://edge.forgecdn.net/files/5000/2/a.jar'));
        $this->assertNull(CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', 'https://edge.forgecdn.net/files/5000/1/b.jar'));
        $this->assertNull(CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', 'https://evil.example/files/5000/1/a.jar'));
        $this->assertNull(CurseForgePackProvider::fileDownloadUrl(5000001, '../a.jar', 'https://edge.forgecdn.net/files/5000/1/..%2Fa.jar'));
        $this->assertNull(CurseForgePackProvider::fileDownloadUrl(5000001, 'a.jar', null));
    }

    public function test_constructed_urls_follow_the_documented_layout(): void
    {
        $this->assertSame('https://edge.forgecdn.net/files/3740/146/jei%201.jar', CurseForgePackProvider::constructedUrl(3740146, 'jei 1.jar'));
        $this->assertSame('https://edge.forgecdn.net/files/4712/6/a.jar', CurseForgePackProvider::constructedUrl(4712006, 'a.jar'));
        $this->assertNull(CurseForgePackProvider::constructedUrl(1, '../a.jar'));
        $this->assertNull(CurseForgePackProvider::constructedUrl(0, 'a.jar'));
    }

    public function test_the_registry_builds_curseforge_even_without_a_key(): void
    {
        Http::fake();
        config()->set('modpacks-plus.curseforge.api_key', null);

        $provider = app(PackProviders::class)->get(CurseForgePackProvider::ID);

        $this->assertInstanceOf(CurseForgePackProvider::class, $provider);
        $this->assertSame('curseforge_not_configured', $this->catchUnavailable(fn () => $provider->search(null, null, null))->reason);
        $this->assertSame([], $provider->categories());
        Http::assertNothingSent();
    }
}
