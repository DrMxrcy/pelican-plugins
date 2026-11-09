<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use ZipArchive;

/** Builds .mrpack fixtures (and the Modrinth API payloads around them) for tests. */
final class Mrpack
{
    public const PACK_ID = 'PACKID01';

    public const VERSION_ID = 'VERSID01';

    public const ARCHIVE_URL = 'https://cdn.modrinth.com/data/PACKID01/versions/VERSID01/pack.mrpack';

    /**
     * A zip with the given entries (name => contents) as bytes.
     *
     * @param  array<string, string>  $entries
     */
    public static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mrpack');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $contents);
            }
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A modrinth.index.json for the given files.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, string>  $dependencies
     */
    public static function index(array $files, array $dependencies = ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.11']): string
    {
        return (string) json_encode([
            'formatVersion' => 1,
            'game' => 'minecraft',
            'versionId' => '1.0.0',
            'name' => 'Test Pack',
            'files' => $files,
            'dependencies' => $dependencies,
        ]);
    }

    /**
     * One index file entry for $contents hosted at $url.
     *
     * @return array<string, mixed>
     */
    public static function file(string $path, string $contents, string $url, string $server = 'required'): array
    {
        return [
            'path' => $path,
            'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)],
            'env' => ['client' => 'required', 'server' => $server],
            'downloads' => [$url],
            'fileSize' => strlen($contents),
        ];
    }

    /**
     * The Modrinth API version object for an archive.
     *
     * @return array<string, mixed>
     */
    public static function version(string $archive, string $url = self::ARCHIVE_URL, ?int $size = null, string $projectId = self::PACK_ID): array
    {
        return [
            'id' => self::VERSION_ID,
            'project_id' => $projectId,
            'name' => 'Test Pack 1.0.0',
            'version_number' => '1.0.0',
            'version_type' => 'release',
            'game_versions' => ['1.20.1'],
            'loaders' => ['fabric'],
            'date_published' => '2026-01-01T00:00:00Z',
            'downloads' => 5,
            'files' => [[
                'url' => $url,
                'filename' => 'pack.mrpack',
                'primary' => true,
                'size' => $size ?? strlen($archive),
                'hashes' => ['sha1' => sha1($archive), 'sha512' => hash('sha512', $archive)],
            ]],
        ];
    }

    /**
     * A version_files lookup answer that knows each of the given files at its URL.
     *
     * @param  array<int, array<string, mixed>>  $indexFiles
     * @return array<string, mixed>
     */
    public static function lookup(array $indexFiles): array
    {
        $answer = [];
        foreach ($indexFiles as $file) {
            $answer[$file['hashes']['sha512']] = [
                'id' => 'v'.substr($file['hashes']['sha1'], 0, 7),
                'files' => [['url' => $file['downloads'][0], 'hashes' => $file['hashes'], 'filename' => basename($file['path'])]],
            ];
        }

        return $answer;
    }
}
