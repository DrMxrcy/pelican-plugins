<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use DrMxrcy\ModpacksPlus\Services\LoaderVersions;
use Illuminate\Support\Facades\Http;

/**
 * Plan 40: small answers in the shape of the loaders' real metadata (checked 2026-11-10),
 * for Http::fake.
 */
final class LoaderMeta
{
    /** @param  array<int, string>  $releases */
    public static function mojang(array $releases = ['26.3', '26.2', '26.1.2', '1.21.1', '1.20.1'], array $snapshots = ['26.4-snapshot-7']): string
    {
        $versions = [];
        foreach ($snapshots as $id) {
            $versions[] = ['id' => $id, 'type' => 'snapshot', 'url' => 'https://piston-meta.mojang.com/v1/packages/x/'.$id.'.json'];
        }
        foreach ($releases as $id) {
            $versions[] = ['id' => $id, 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/x/'.$id.'.json'];
        }
        $versions[] = ['id' => 'b1.7.3', 'type' => 'old_beta', 'url' => 'https://piston-meta.mojang.com/v1/packages/x/b1.7.3.json'];

        return (string) json_encode(['latest' => ['release' => $releases[0] ?? '', 'snapshot' => $snapshots[0] ?? ''], 'versions' => $versions]);
    }

    /** @param  array<string, bool>  $games  version => stable */
    public static function games(array $games = ['26.4-snapshot-7' => false, '26.3' => true, '26.2' => true, '26.1.2' => true, '1.21.1' => true, '1.20.1' => true, '3D Shareware v1.34' => true]): string
    {
        $out = [];
        foreach ($games as $version => $stable) {
            $out[] = ['version' => (string) $version, 'stable' => $stable];
        }

        return (string) json_encode($out);
    }

    /** @param  array<string, bool>  $loaders  version => stable */
    public static function fabricLoaders(array $loaders = ['0.20.1' => true, '0.20.0' => false, '0.19.5' => true]): string
    {
        $out = [];
        foreach ($loaders as $version => $stable) {
            $out[] = ['separator' => '.', 'build' => 1, 'maven' => 'net.fabricmc:fabric-loader:'.$version, 'version' => (string) $version, 'stable' => $stable];
        }

        return (string) json_encode($out);
    }

    /** @param  array<int, string>  $versions */
    public static function maven(array $versions, string $artifact = 'neoforge'): string
    {
        $items = implode('', array_map(fn (string $version) => "\n      <version>$version</version>", $versions));

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<metadata>\n  <groupId>net</groupId>\n  <artifactId>$artifact</artifactId>\n  <versioning>\n    <versions>$items\n    </versions>\n  </versioning>\n</metadata>\n";
    }

    /** @return array<int, string> */
    public static function neoforgeVersions(): array
    {
        return ['21.1.77', '21.1.264', '21.4.0-beta', '20.4.237', '26.1.0.0-alpha.1+snapshot-1', '26.1.2.125', '26.1.2.126', '26.3.0.101', '26.3.0.102'];
    }

    /** @return array<int, string> */
    public static function forgeVersions(): array
    {
        return ['26.3-66.1.5', '26.3-66.1.4', '1.20.1-47.3.0', '1.20.1-47.4.29', '1.12.2-14.23.4.2720-4627', '1.11-13.19.0.2129-1.11.x'];
    }

    /**
     * Fakes every metadata host (on top of whatever else the test fakes).
     *
     * @return array<string, mixed>
     */
    public static function stubs(): array
    {
        return [
            LoaderVersions::MOJANG_URL => Http::response(self::mojang()),
            LoaderVersions::FABRIC_GAME_URL => Http::response(self::games()),
            LoaderVersions::FABRIC_LOADER_URL => Http::response(self::fabricLoaders()),
            LoaderVersions::QUILT_GAME_URL => Http::response(self::games()),
            LoaderVersions::NEOFORGE_URL => Http::response(self::maven(self::neoforgeVersions())),
            LoaderVersions::FORGE_URL => Http::response(self::maven(self::forgeVersions(), 'forge')),
        ];
    }

    /** Whether a request went to one of the metadata hosts. */
    public static function isMetadataRequest(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['piston-meta.mojang.com', 'meta.fabricmc.net', 'meta.quiltmc.org', 'maven.neoforged.net', 'maven.minecraftforge.net', 'files.minecraftforge.net'], true);
    }
}
