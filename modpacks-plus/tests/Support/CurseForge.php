<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

/** Builds CurseForge API payloads and pack zips for tests. */
final class CurseForge
{
    public const KEY = '$2a$10$SECRETkeyDoNotLeakAbcdefghijklmnopqrstuvwxyz0123';

    public const API = 'api.curseforge.com/v1/';

    public const PACK_ID = 123456;

    public const FILE_ID = 4000001;

    public const SERVER_FILE_ID = 4000002;

    public const PACK_FILE_NAME = 'Test CF Pack-1.0.0.zip';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mod(int $id = self::PACK_ID, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'gameId' => 432,
            'classId' => 4471,
            'name' => 'Test CF Pack',
            'slug' => 'test-cf-pack',
            'summary' => 'A test pack',
            'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/test-cf-pack'],
            'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/avatars/thumbnails/1/2/64/64/logo.png'],
            'screenshots' => [
                ['title' => 'Shot', 'url' => 'https://media.forgecdn.net/attachments/1/2/shot.png'],
                ['title' => 'Plain', 'url' => 'http://media.forgecdn.net/attachments/1/2/plain.png'],
                ['title' => 'Quote', 'url' => 'https://media.forgecdn.net/a"onerror="x.png'],
            ],
            'downloadCount' => 12345.0,
            'thumbsUpCount' => 7,
            'dateModified' => '2026-10-01T00:00:00Z',
            'authors' => [['name' => 'Author']],
            'categories' => [['name' => 'Tech']],
            'latestFilesIndexes' => [['gameVersion' => '1.20.1', 'modLoader' => 1, 'fileId' => self::FILE_ID]],
            'allowModDistribution' => true,
        ], $overrides);
    }

    /**
     * A mod (class 6 = mods) used inside a pack.
     *
     * @return array<string, mixed>
     */
    public static function modEntry(int $id, string $name, ?bool $allowDistribution = true, int $classId = 6): array
    {
        return [
            'id' => $id,
            'gameId' => 432,
            'classId' => $classId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/mc-mods/'.strtolower(str_replace(' ', '-', $name))],
            'allowModDistribution' => $allowDistribution,
        ];
    }

    public static function url(int $fileId, string $name): string
    {
        return 'https://edge.forgecdn.net/files/'.intdiv($fileId, 1000).'/'.($fileId % 1000).'/'.rawurlencode($name);
    }

    /**
     * A file payload for $contents.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function file(int $id, int $modId, string $name, string $contents, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'modId' => $modId,
            'displayName' => pathinfo($name, PATHINFO_FILENAME),
            'fileName' => $name,
            'fileLength' => strlen($contents),
            'hashes' => [['value' => sha1($contents), 'algo' => 1], ['value' => md5($contents), 'algo' => 2]],
            'downloadUrl' => self::url($id, $name),
            'gameVersions' => ['1.20.1', 'Forge'],
            'releaseType' => 1,
            'fileDate' => '2026-10-0'.($id % 9 + 1).'T00:00:00Z',
            'downloadCount' => 10,
            'isServerPack' => false,
            'serverPackFileId' => null,
        ], $overrides);
    }

    /**
     * A manifest.json for the given [projectID, fileID] pairs.
     *
     * @param  array<int, array{int, int}|array{int, int, bool}>  $files
     * @param  array<string, mixed>  $extra
     */
    public static function manifest(array $files, string $loader = 'forge-47.2.0', string $minecraft = '1.20.1', array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'minecraft' => ['version' => $minecraft, 'modLoaders' => [['id' => $loader, 'primary' => true]]],
            'manifestType' => 'minecraftModpack',
            'manifestVersion' => 1,
            'name' => 'Test CF Pack',
            'version' => '1.0.0',
            'author' => 'Author',
            'files' => array_map(fn (array $file) => ['projectID' => $file[0], 'fileID' => $file[1], 'required' => $file[2] ?? true], $files),
            'overrides' => 'overrides',
        ], $extra));
    }

    /**
     * A {data: …} response body.
     *
     * @return array{data: mixed}
     */
    public static function data(mixed $data): array
    {
        return ['data' => $data];
    }
}
