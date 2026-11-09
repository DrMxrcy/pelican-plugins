<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

/**
 * FTB (modpacks.ch) API payloads for tests, shaped like the real answers of
 * GET /public/modpack/{id} and GET /public/modpack/{id}/{version} (2026-11-09).
 */
final class Ftb
{
    public const API = 'api.modpacks.ch/public/';

    public const PACK_ID = 100;

    public const VERSION_ID = 2000;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function pack(int $id = self::PACK_ID, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'FTB Test Pack',
            'synopsis' => 'A test pack',
            'description' => "# Test\nSome **markdown**.",
            'art' => [
                ['id' => 1, 'type' => 'square', 'url' => 'https://apps.modpacks.ch/modpacks/art/1/logo.png', 'sha1' => str_repeat('a', 40), 'size' => 10],
                ['id' => 2, 'type' => 'splash', 'url' => 'https://apps.modpacks.ch/modpacks/art/1/splash.png', 'sha1' => str_repeat('b', 40), 'size' => 10],
                ['id' => 3, 'type' => 'splash', 'url' => 'http://apps.modpacks.ch/modpacks/art/1/plain.png', 'sha1' => str_repeat('c', 40), 'size' => 10],
            ],
            'authors' => [['id' => 8, 'name' => 'FTB Team', 'type' => 'team']],
            'versions' => [
                self::versionEntry(self::VERSION_ID - 1, '0.9.0', 1700000000),
                self::versionEntry(self::VERSION_ID, '1.0.0', 1700000100),
                self::versionEntry(self::VERSION_ID + 1, '1.1.0-private', 1700000200, ['private' => true]),
            ],
            'installs' => 1234,
            'plays' => 99,
            'tags' => [['id' => 9, 'name' => 'FTB'], ['id' => 13, 'name' => 'Tech']],
            'status' => 'success',
            'private' => false,
            'provider' => 'modpacks.ch',
            'type' => 'release',
            'updated' => 1700000100,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function versionEntry(int $id, string $name, int $updated, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'type' => 'release',
            'updated' => $updated,
            'private' => false,
            'specs' => ['id' => 1, 'minimum' => 4096, 'recommended' => 6144],
            'targets' => self::targets(),
        ], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    public static function targets(string $loader = 'forge', string $loaderVersion = '47.2.0', string $minecraft = '1.20.1'): array
    {
        return [
            ['version' => $loaderVersion, 'id' => 1, 'name' => $loader, 'type' => 'modloader', 'updated' => 1],
            ['version' => $minecraft, 'id' => 2, 'name' => 'minecraft', 'type' => 'game', 'updated' => 1],
            ['version' => '17.0.2+8', 'id' => 3, 'name' => 'java', 'type' => 'runtime', 'updated' => 1],
        ];
    }

    /**
     * A version answer with $files.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function version(array $files, array $overrides = [], int $id = self::VERSION_ID, int $parent = self::PACK_ID): array
    {
        return array_merge([
            'id' => $id,
            'parent' => $parent,
            'name' => '1.0.0',
            'type' => 'release',
            'updated' => 1700000100,
            'private' => false,
            'status' => 'success',
            'targets' => self::targets(),
            'specs' => ['id' => 1, 'minimum' => 4096, 'recommended' => 6144],
            'files' => $files,
        ], $overrides);
    }

    public static function distUrl(string $path, string $contents): string
    {
        return 'https://dist.modpacks.ch/modpacks/100/FTB%20Test%20Pack-1.0.0/'.trim($path, './').'/'.sha1($contents);
    }

    /**
     * A file FTB hosts itself (dist.modpacks.ch).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function file(string $path, string $name, string $contents, string $type = 'config', array $overrides = []): array
    {
        return array_merge([
            'version' => '1',
            'path' => $path,
            'url' => self::distUrl($path.'/'.$name, $contents),
            'mirrors' => [],
            'sha1' => sha1($contents),
            'size' => strlen($contents),
            'tags' => [],
            'clientonly' => false,
            'serveronly' => false,
            'optional' => false,
            'id' => crc32($path.$name),
            'name' => $name,
            'type' => $type,
            'updated' => 1700000000,
        ], $overrides);
    }

    /**
     * A mod FTB takes from CurseForge: its URL is on edge.forgecdn.net and it names the
     * CurseForge project and file (as strings, like the real API).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function curseForgeMod(string $name, string $contents, int $project, int $file, array $overrides = []): array
    {
        return self::file('./mods', $name, $contents, 'mod', array_merge([
            'url' => CurseForge::url($file, $name),
            'curseforge' => ['project' => (string) $project, 'file' => (string) $file],
        ], $overrides));
    }
}
