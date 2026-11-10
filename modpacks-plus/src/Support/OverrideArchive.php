<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use ZipArchive;

/**
 * Plan 42: one zip of override files, built by the panel so a big override set costs one
 * daemon write and one daemon unpack instead of one write per file.
 *
 * Threat: the node unpacking something a pack author controls (zip-slip, a symlink, a
 * protected path, a world). The pack's own archive is never handed to the node: every
 * entry of this zip is added here, one by one, under the target path PlanValidator derived
 * for an override that already passed validation, re-checked here (safe relative path,
 * derived again from the entry name, not protected, not Modrinth metadata, no duplicate,
 * size). Contents are read through PackArchiveReader (capped, declared size enforced).
 * Every entry is a regular file with mode 0644; there are no directory entries (the node
 * creates parents). The finished zip is opened again and must hold exactly those entries,
 * so `paths` is what the archive really contains, not what was asked for.
 */
final class OverrideArchive
{
    /** Where the zip is staged on the server (the plugin's own folder). */
    public const STAGING_FOLDER = PlanValidator::OWN_FOLDER.'/staging';

    /** Uncompressed bytes per archive (a single larger override still gets one of its own). */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /** Entries per archive. */
    public const CHUNK_ENTRIES = 2000;

    /** Unix mode of every entry: a regular file, rw-r--r-- (what a daemon write creates). */
    public const FILE_MODE = 0100644;

    /** `.modpacks-plus/staging/overrides-<install>-<chunk>-<attempt>.zip`, nothing else. */
    private const STAGING_PATTERN = '#^\.modpacks-plus/staging/overrides-[1-9][0-9]{0,18}-[0-9]{1,6}-[1-9][0-9]{0,2}\.zip$#';

    /** @param  array<int, string>  $paths */
    private function __construct(
        public readonly string $contents,
        public readonly array $paths,
    ) {}

    /** The staged file name of one attempt at one chunk; a retry never rewrites a file the node may still read. */
    public static function stagingPath(int $installId, int $chunk, int $attempt): string
    {
        return self::STAGING_FOLDER.'/'.self::stagingPrefix($installId).$chunk.'-'.$attempt.'.zip';
    }

    /** File-name prefix of every archive staged for an install (cleanup lists the folder by it). */
    public static function stagingPrefix(int $installId): string
    {
        return 'overrides-'.$installId.'-';
    }

    /** Whether $path is a staged override archive (the only file the node is ever asked to unpack). */
    public static function isStagingPath(string $path): bool
    {
        return PlanValidator::isSafeRelativePath($path) && preg_match(self::STAGING_PATTERN, $path) === 1;
    }

    /**
     * Splits entries, in order, into archives of at most $maxBytes uncompressed and
     * $maxEntries files; an entry larger than $maxBytes gets an archive of its own.
     *
     * @param  array<int, OverrideEntry>  $entries
     * @return array<int, array<int, OverrideEntry>>
     */
    public static function chunks(array $entries, int $maxBytes = self::CHUNK_BYTES, int $maxEntries = self::CHUNK_ENTRIES): array
    {
        $chunks = [];
        $current = [];
        $bytes = 0;

        foreach (array_values($entries) as $entry) {
            $size = max(0, $entry->size);
            if ($current !== [] && ($bytes + $size > $maxBytes || count($current) >= $maxEntries)) {
                $chunks[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $entry;
            $bytes += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Builds the zip in $workFile (on the panel), reads it back and returns its bytes. The
     * work file is always removed.
     *
     * @param  array<int, OverrideEntry>  $entries  validated overrides (InstallPlan::$overrides after PlanValidator::validate())
     * @param  ?string  $root  the plan's override root (InstallPlan::overrideRoot())
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public static function build(array $entries, PackArchiveReader $reader, PlanValidator $validator, ?string $root, Caps $caps, string $workFile): self
    {
        if ($entries === [] || count($entries) > self::CHUNK_ENTRIES) {
            throw new UnsafePlanException('too_many_entries', (string) count($entries));
        }

        $expected = [];
        $seen = [];
        $total = 0;
        foreach ($entries as $entry) {
            $path = $validator->normalisePath($entry->path);

            if ($validator->overrideTarget($entry->entry, $root) !== $path) {
                throw new UnsafePlanException('unsafe_path', $entry->entry);
            }
            if ($validator->isProtected($path) || PlanValidator::isModrinthMetadata($path)) {
                throw new UnsafePlanException('protected_path', $path);
            }
            if (isset($seen[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $seen[strtolower($path)] = true;

            if ($entry->size < 0 || $entry->size > $caps->maxOverrideBytes) {
                throw new UnsafePlanException('override_too_large', $path);
            }
            $total += $entry->size;
            $expected[$path] = $entry->size;
        }

        if (count($entries) > 1 && $total > max(self::CHUNK_BYTES, $caps->maxOverrideBytes)) {
            throw new UnsafePlanException('override_too_large', (string) $total);
        }

        try {
            self::write($entries, $reader, $caps, $workFile, $expected);
            $paths = self::readBack($workFile, $expected);

            $contents = file_get_contents($workFile);
            if (!is_string($contents)) {
                throw new PackUnavailableException('bad_archive', 'override archive');
            }

            return new self($contents, $paths);
        } finally {
            if (is_file($workFile)) {
                @unlink($workFile);
            }
        }
    }

    /**
     * @param  array<int, OverrideEntry>  $entries
     * @param  array<string, int>  $expected  path => size
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private static function write(array $entries, PackArchiveReader $reader, Caps $caps, string $workFile, array $expected): void
    {
        $directory = dirname($workFile);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PackUnavailableException('bad_archive', 'override archive');
        }

        $zip = new ZipArchive();
        if ($zip->open($workFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PackUnavailableException('bad_archive', 'override archive');
        }

        try {
            foreach ($entries as $entry) {
                $contents = $reader->read($entry->entry, $caps->maxOverrideBytes);
                // The size the node is later checked against: the plan's, which the reader enforced too.
                if (strlen($contents) !== $expected[$entry->path]) {
                    throw new PackUnavailableException('bad_archive', $entry->entry.' size');
                }

                if (!$zip->addFromString($entry->path, $contents, ZipArchive::FL_ENC_UTF_8)
                    || !$zip->setExternalAttributesName($entry->path, ZipArchive::OPSYS_UNIX, self::FILE_MODE << 16)
                    || !$zip->setCompressionName($entry->path, ZipArchive::CM_DEFLATE)) {
                    throw new PackUnavailableException('bad_archive', 'override archive');
                }
            }
        } catch (\Throwable $exception) {
            $zip->unchangeAll();
            $zip->close();

            throw $exception;
        }

        if (!$zip->close()) {
            throw new PackUnavailableException('bad_archive', 'override archive');
        }
    }

    /**
     * The built zip must hold exactly the expected regular files, unencrypted, at their sizes.
     *
     * @param  array<string, int>  $expected  path => size
     * @return array<int, string>
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private static function readBack(string $workFile, array $expected): array
    {
        $zip = new ZipArchive();
        if ($zip->open($workFile, ZipArchive::RDONLY) !== true) {
            throw new PackUnavailableException('bad_archive', 'override archive');
        }

        try {
            if ($zip->numFiles !== count($expected)) {
                throw new UnsafePlanException('unsafe_path', 'override archive');
            }

            $paths = [];
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw new PackUnavailableException('bad_archive', 'override archive');
                }

                $name = (string) $stat['name'];
                $opsys = 0;
                $attributes = 0;
                if (!array_key_exists($name, $expected) || isset($seen[$name])
                    || (int) $stat['size'] !== $expected[$name]
                    || $stat['encryption_method'] !== 0
                    || !$zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    || $opsys !== ZipArchive::OPSYS_UNIX
                    || (($attributes >> 16) & 0177777) !== self::FILE_MODE) {
                    throw new UnsafePlanException('unsafe_path', $name);
                }
                // A list, not keys: a numeric file name ("123") would turn into an int key.
                $seen[$name] = true;
                $paths[] = $name;
            }

            return $paths;
        } finally {
            $zip->close();
        }
    }
}
