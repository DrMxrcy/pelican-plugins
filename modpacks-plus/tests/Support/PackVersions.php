<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Several versions of one Modrinth pack on a fake Modrinth (plan 36 update and switch tests).
 * Needs InstallScenario. Every answer is built per request, so files can be read again.
 */
trait PackVersions
{
    /** @var array<string, array{version: array<string, mixed>, archive: string, pack: string}> by version id */
    protected array $versions = [];

    /** @var array<string, array<string, mixed>> index entries of every version, by URL */
    protected array $indexFiles = [];

    /**
     * Adds a version: `$files` path => contents (downloaded from cdn.modrinth.com), `$overrides`
     * archive entry => contents.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $dependencies
     */
    protected function packVersion(string $versionId, string $number, array $files, array $overrides = [], string $packId = Mrpack::PACK_ID, array $dependencies = ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.11']): void
    {
        $index = [];
        foreach ($files as $path => $contents) {
            $url = 'https://cdn.modrinth.com/data/'.substr(sha1($path), 0, 8).'/versions/'.substr(sha1($contents), 0, 8).'/'.basename($path);
            $entry = Mrpack::file($path, $contents, $url);
            $index[] = $entry;
            $this->indexFiles[$url] = $entry;
            $this->files->remote[$url] = $contents;
        }

        $archive = Mrpack::zip(array_merge(['modrinth.index.json' => Mrpack::index($index, $dependencies)], $overrides));
        $url = "https://cdn.modrinth.com/data/$packId/versions/$versionId/pack.mrpack";
        $version = Mrpack::version($archive, $url, projectId: $packId);
        $version['id'] = $versionId;
        $version['version_number'] = $number;
        $version['date_published'] = '2026-0'.(count($this->versions) + 1).'-01T00:00:00Z';
        $version['loaders'] = array_values(array_intersect_key(['fabric-loader' => 'fabric', 'forge' => 'forge', 'neoforge' => 'neoforge', 'quilt-loader' => 'quilt'], $dependencies));
        $version['game_versions'] = [$dependencies['minecraft']];

        $this->versions[$versionId] = ['version' => $version, 'archive' => $archive, 'pack' => $packId];
        $this->fakeVersions();
    }

    protected function fakeVersions(): void
    {
        Http::swap(new HttpFactory());

        $stubs = [
            'api.modrinth.com/v2/version_files' => fn () => Http::response(Mrpack::lookup(array_values($this->indexFiles))),
        ];
        $byPack = [];
        foreach ($this->versions as $id => $entry) {
            $stubs['api.modrinth.com/v2/version/'.$id] = fn () => Http::response($entry['version']);
            $stubs[$entry['version']['files'][0]['url']] = fn () => Http::response($entry['archive']);
            $byPack[$entry['pack']][] = $entry['version'];
        }
        foreach ($byPack as $pack => $versions) {
            $stubs['api.modrinth.com/v2/project/'.$pack.'/version'] = fn () => Http::response(array_reverse($versions));
            $stubs['api.modrinth.com/v2/project/'.$pack] = fn () => Http::response([
                'id' => $pack, 'slug' => strtolower($pack), 'title' => "Pack $pack", 'project_type' => 'modpack',
                'description' => 'd', 'body' => '', 'icon_url' => null, 'gallery' => [],
            ]);
        }
        foreach ($this->indexFiles as $url => $entry) {
            $stubs[$url] = fn () => Http::response($this->files->remote[$url]);
        }

        Http::fake($stubs);
    }

    /**
     * Starts an install (or, with ['update' => true], an update) and runs it to the end.
     *
     * @param  array<string, mixed>  $options
     * @param  array{loader: string, minecraft: string, egg_id: int, egg_name: string}|null  $loaderSwitch
     */
    protected function runVersion(Server $server, string $versionId, array $options = [], ?\Closure $between = null, string $packId = Mrpack::PACK_ID, ?array $loaderSwitch = null): PackInstall
    {
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, $packId, $versionId, "Pack $packId", $this->versions[$versionId]['version']['version_number'], null, $loaderSwitch, $options);
        $this->runJobs(between: function ($job, PackInstall $install) use ($server, $between) {
            $this->finishBackups($server);
            if ($between !== null) {
                $between($job, $install);
            }
        });

        return $this->latestInstall();
    }

    /** @return array<string, string> worlds, player lists and server.properties as they are now */
    protected function playerData(): array
    {
        $data = array_filter($this->files->files, fn (string $path) => preg_match('#^(world|world_nether|world_the_end)/|^(ops|whitelist|banned-players|banned-ips|usercache)\.json$|^server\.properties$#', $path) === 1, ARRAY_FILTER_USE_KEY);
        ksort($data);

        return $data;
    }

    protected function seedPlayerData(): void
    {
        $this->files->seed([
            'world/level.dat' => 'level',
            'world/region/r.0.0.mca' => 'region',
            'world_nether/DIM-1/x' => 'nether',
            'world_the_end/DIM1/y' => 'end',
            'ops.json' => '[{"name":"owner"}]',
            'whitelist.json' => '[{"name":"friend"}]',
            'banned-players.json' => '[{"name":"griefer"}]',
            'banned-ips.json' => '[{"ip":"10.0.0.1"}]',
            'usercache.json' => '[]',
            'server.properties' => "motd=hi\nlevel-name=world\n",
            'eula.txt' => 'eula=true',
        ]);
    }
}
