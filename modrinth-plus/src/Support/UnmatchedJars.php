<?php

namespace DrMxrcy\ModrinthPlus\Support;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use Illuminate\Support\Facades\Cache;

/**
 * Jars in the mods/plugins folder that the metadata file does not list ("untracked"), and the
 * ones the last "Scan folder" run found no Modrinth match for ("Not on Modrinth").
 *
 * Ruling: the scan result is kept in the panel cache, keyed by server and project type, as
 * `filename => size`. Telling an untracked jar apart from a non-Modrinth one means hashing it,
 * which is too slow for every render of the Installed tab, and the metadata file only holds
 * projects (writing other keys there would be dropped by every later install). A row only shows
 * while the file is still on disk, still untracked and still the same size; a cleared cache
 * just hides the rows until the next scan. Nothing here touches files on the server.
 */
final class UnmatchedJars
{
    protected const CACHE_DAYS = 30;

    /**
     * Untracked `.jar` files of a folder listing (`DaemonFileRepository::getDirectory()`), in
     * listing order. A file counts as tracked when the metadata lists its name (case-insensitive,
     * the way the installed-file check has always compared names).
     *
     * @param  array<mixed>  $listing
     * @param  array<int, array<string, mixed>>  $installedMods  metadata entries
     * @return list<array{name: string, size: int, modified: ?string}>
     */
    public static function untracked(array $listing, array $installedMods): array
    {
        $tracked = [];
        foreach ($installedMods as $mod) {
            if (is_string($mod['filename'] ?? null)) {
                $tracked[strtolower($mod['filename'])] = true;
            }
        }

        $files = [];
        foreach ($listing as $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null)) {
                continue;
            }

            $name = $file['name'];

            if (($file['directory'] ?? false) === true || ($file['file'] ?? true) === false) {
                continue;
            }

            if (!str_ends_with(strtolower($name), '.jar') || isset($tracked[strtolower($name)])) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'size' => is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0,
                'modified' => is_string($file['modified'] ?? null) ? $file['modified'] : null,
            ];
        }

        return $files;
    }

    /** A plain file name the page can later delete safely (no path parts, no traversal). */
    public static function isSafeName(string $name): bool
    {
        return $name !== '' && $name !== '.' && !str_contains($name, "\0") && !str_contains($name, '..')
            && !str_contains($name, '/') && !str_contains($name, '\\');
    }

    /**
     * The untracked files the last scan found no Modrinth match for, unchanged since.
     *
     * @param  list<array{name: string, size: int, modified: ?string}>  $untracked  see untracked()
     * @return list<array{name: string, size: int, modified: ?string}>
     */
    public static function notOnModrinth(int $serverId, ModrinthProjectType $type, array $untracked): array
    {
        $remembered = self::remembered($serverId, $type);

        if ($remembered === []) {
            return [];
        }

        return array_values(array_filter(
            $untracked,
            fn (array $file) => array_key_exists($file['name'], $remembered) && $remembered[$file['name']] === $file['size'],
        ));
    }

    /** @return array<string, int> filename => size */
    public static function remembered(int $serverId, ModrinthProjectType $type): array
    {
        $value = Cache::get(self::cacheKey($serverId, $type));

        if (!is_array($value)) {
            return [];
        }

        return array_filter($value, fn ($size, $name) => is_string($name) && is_int($size), ARRAY_FILTER_USE_BOTH);
    }

    /** @param  array<string, int>  $files  filename => size; replaces the previous scan's result */
    public static function remember(int $serverId, ModrinthProjectType $type, array $files): void
    {
        if ($files === []) {
            Cache::forget(self::cacheKey($serverId, $type));

            return;
        }

        Cache::put(self::cacheKey($serverId, $type), $files, now()->addDays(self::CACHE_DAYS));
    }

    public static function forget(int $serverId, ModrinthProjectType $type, string $filename): void
    {
        $files = self::remembered($serverId, $type);
        unset($files[$filename]);

        self::remember($serverId, $type, $files);
    }

    protected static function cacheKey(int $serverId, ModrinthProjectType $type): string
    {
        return "modrinth_unmatched_jars:$serverId:{$type->value}";
    }
}
