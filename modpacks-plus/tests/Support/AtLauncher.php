<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

/**
 * ATLauncher payloads for tests, shaped like GET /v1/packs/full/public, GET /v1/pack/{name}
 * and a version's Configs.json on download.nodecdn.net/containers/atl (2026-11-09).
 */
final class AtLauncher
{
    public const API = 'api.atlauncher.com/v1/';

    public const CDN = 'download.nodecdn.net/containers/atl/';

    public const PACK = 'TestPack';

    public const VERSION = 'v1.0.0';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function pack(string $safeName = self::PACK, array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'name' => 'Test Pack',
            'safeName' => $safeName,
            'type' => 'public',
            'versions' => [
                ['version' => self::VERSION, 'minecraft' => '1.20.1', 'published' => 1700000100, '__LINK' => 'https://api.atlauncher.com/v1/pack/'.$safeName.'/'.self::VERSION],
                ['version' => 'v0.9.0', 'minecraft' => '1.19.2', 'published' => 1700000000, '__LINK' => 'https://api.atlauncher.com/v1/pack/'.$safeName.'/v0.9.0'],
            ],
            'description' => 'A test pack for servers',
            'discordInviteURL' => null,
            'supportURL' => null,
            'websiteURL' => null,
        ], $overrides);
    }

    /** @return array{error: bool, code: int, message: null, data: mixed} */
    public static function data(mixed $data): array
    {
        return ['error' => false, 'code' => 200, 'message' => null, 'data' => $data];
    }

    /**
     * A Configs.json.
     *
     * @param  array<int, array<string, mixed>>  $mods
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function configs(array $mods, ?string $zip = null, array $overrides = []): array
    {
        return array_merge([
            'enableCurseIntegration' => false,
            'enableEditingMods' => false,
            'version' => self::VERSION,
            'minecraft' => '1.20.1',
            'noConfigs' => $zip === null,
            'caseAllFiles' => 'lower',
            'java' => ['min' => 17, 'max' => 17],
            'loader' => [
                'type' => 'forge',
                'choose' => false,
                'metadata' => ['minecraft' => '1.20.1', 'version' => '47.2.0', 'rawVersion' => '1.20.1-47.2.0'],
                'className' => 'com.atlauncher.data.loaders.forge.Forge113Loader',
            ],
            'mods' => $mods,
            'configs' => $zip !== null ? ['filesize' => strlen($zip), 'sha1' => sha1($zip)] : null,
        ], $overrides);
    }

    public static function configsUrl(string $file = 'Configs.json', string $pack = self::PACK, string $version = self::VERSION): string
    {
        return 'https://'.self::CDN.'packs/'.$pack.'/versions/'.$version.'/'.$file;
    }

    public static function fileUrl(string $file, string $pack = self::PACK): string
    {
        return 'https://'.self::CDN.'packs/'.$pack.'/files/'.$file;
    }

    /**
     * A mod ATLauncher hosts (`download: server`), md5 only, naming its CurseForge file.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mod(string $file, string $contents, ?int $curseId = null, ?int $curseFileId = null, array $overrides = []): array
    {
        return array_merge([
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'version' => '1.0',
            'url' => 'packs/'.self::PACK.'/files/'.$file,
            'file' => $file,
            'download' => 'server',
            'md5' => md5($contents),
            'filesize' => strlen($contents),
            'curse_id' => $curseId,
            'curse_file_id' => $curseFileId,
            'force' => false,
            'type' => 'mods',
            'client' => true,
            'optional' => false,
            'server' => true,
            'selected' => false,
            'recommended' => true,
            'hidden' => false,
            'library' => false,
            'description' => 'A mod',
            'website' => $curseId !== null ? 'https://www.curseforge.com/minecraft/mc-mods/'.strtolower(pathinfo($file, PATHINFO_FILENAME)) : 'https://example.com/',
            'authors' => ['someone'],
        ], $overrides);
    }
}
