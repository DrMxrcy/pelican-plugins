<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * `/v2/projects` has no author, so installed rows only had one when the metadata entry stored
 * it (projects installed from a search row). Dependencies and scanned jars were blank; their
 * author now comes from the search index, which names it the same way the All tab does.
 */
class InstalledAuthorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string} */
    private function mod(string $id, ?string $author = null): array
    {
        $mod = [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => $id,
            'version_id' => "v-$id",
            'version_number' => '1.0',
            'filename' => "$id.jar",
            'installed_at' => '2026-11-09T00:00:00+00:00',
        ];

        if ($author !== null) {
            $mod['author'] = $author;
        }

        return $mod;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        return array_column($rows, null, 'project_id');
    }

    public function test_missing_authors_are_filled_from_the_search_index(): void
    {
        Http::fake([
            'api.modrinth.com/v2/projects*' => Http::response([
                ['id' => 'P7dR8mSH', 'slug' => 'fabric-api', 'title' => 'Fabric API'],
                ['id' => 'xaero', 'slug' => 'xaeros-minimap', 'title' => "Xaero's Minimap"],
            ]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [
                ['project_id' => 'P7dR8mSH', 'author' => 'modmuss50'],
            ], 'total_hits' => 1]),
        ]);

        $rows = $this->byId(app(MinecraftModrinthService::class)->getInstalledModsFromModrinth([
            $this->mod('P7dR8mSH'),
            $this->mod('xaero', 'thexaero'),
        ]));

        $this->assertSame('modmuss50', $rows['P7dR8mSH']['author']);
        $this->assertSame('thexaero', $rows['xaero']['author']);

        // Only the project without a stored author is looked up.
        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/v2/search')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['facets'] === '[["project_id:P7dR8mSH"]]';
        });
    }

    public function test_no_search_when_every_author_is_known(): void
    {
        Http::fake([
            'api.modrinth.com/v2/projects*' => Http::response([['id' => 'xaero', 'slug' => 'x', 'title' => 'X']]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => []]),
        ]);

        app(MinecraftModrinthService::class)->getInstalledModsFromModrinth([$this->mod('xaero', 'thexaero')]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/search'));
    }

    public function test_a_failed_author_lookup_leaves_the_author_blank(): void
    {
        Http::fake([
            'api.modrinth.com/v2/projects*' => Http::response([['id' => 'P7dR8mSH', 'slug' => 'fabric-api', 'title' => 'Fabric API']]),
            'api.modrinth.com/v2/search*' => Http::response('down', 500),
        ]);

        $rows = $this->byId(app(MinecraftModrinthService::class)->getInstalledModsFromModrinth([$this->mod('P7dR8mSH')]));

        $this->assertArrayHasKey('P7dR8mSH', $rows);
        $this->assertSame('', $rows['P7dR8mSH']['author'] ?? '');
    }

    public function test_authors_are_cached(): void
    {
        Http::fake([
            'api.modrinth.com/v2/projects*' => Http::response([['id' => 'P7dR8mSH', 'slug' => 'fabric-api', 'title' => 'Fabric API']]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [['project_id' => 'P7dR8mSH', 'author' => 'modmuss50']]]),
        ]);

        $service = app(MinecraftModrinthService::class);
        $service->getInstalledModsFromModrinth([$this->mod('P7dR8mSH')]);
        $rows = $this->byId($service->getInstalledModsFromModrinth([$this->mod('P7dR8mSH')]));

        $this->assertSame('modmuss50', $rows['P7dR8mSH']['author']);
        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), '/v2/search'))->count());
    }
}
