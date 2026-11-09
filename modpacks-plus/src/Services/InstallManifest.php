<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * The installed-files manifest (plan 36): every file a pack install put on the server, with
 * its size and hash, recorded at finalize. An update diffs the previous manifest against the
 * new version (PackDiff) and compares the server's files with it to find user changes.
 *
 * Entries: `{path, size, hash, kept}`, `hash` = `sha512:<hex>`, `sha1:<hex>` or (ATLauncher md5-only
 * files, plan 32) `md5:<hex>`. Downloads use
 * the plan's strongest hash; overrides the sha512 of the archive entry written. `kept` marks a
 * file the user changed and an update kept: recorded with the pack's hash, so it still reads
 * as user-modified next time. Protected, client-only and skipped files are never recorded.
 */
final class InstallManifest
{
    /** Hash algorithms a manifest entry may carry (hex lengths). */
    public const ALGORITHMS = ['sha512' => 128, 'sha1' => 40, 'md5' => 32];

    /**
     * The manifest of a validated plan: downloads first, then overrides replacing the same path
     * (they are written after the downloads).
     *
     * @param  array<string, array{size: int, hash: string}>  $overrideHashes  from overrideHashes()
     * @param  array<int, string>  $kept  paths the user changed that an update kept
     * @return array<int, array{path: string, size: int, hash: string, kept: bool}>
     *
     * @throws UnsafePlanException
     */
    public static function build(InstallPlan $plan, PlanValidator $validator, array $overrideHashes, array $kept = []): array
    {
        $entries = [];
        foreach ($plan->files as $file) {
            if ($validator->isProtected($file->path)) {
                continue;
            }
            // The digest the download was checked against (ATLauncher's md5 only for its md5-only
            // files, plan 32); here it only tells whether the user changed the file since.
            [$algorithm, $digest] = PlanValidator::fileHash($file);
            $entries[$file->path] = ['path' => $file->path, 'size' => $file->size, 'hash' => $algorithm.':'.$digest];
        }

        foreach ($plan->overrides as $override) {
            if ($validator->isProtected($override->path) || !isset($overrideHashes[$override->path])) {
                continue;
            }
            $entries[$override->path] = ['path' => $override->path] + $overrideHashes[$override->path];
        }

        $keptPaths = array_flip($kept);
        $list = [];
        foreach ($entries as $path => $entry) {
            $list[] = $entry + ['kept' => isset($keptPaths[$path])];
        }
        usort($list, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return $list;
    }

    /**
     * The size and sha512 of every override a plan writes, read from its archive on the panel
     * (each entry re-checked: its target must be the plan's). Protected targets are left out.
     *
     * @return array<string, array{size: int, hash: string}>
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public static function overrideHashes(InstallPlan $plan, string $archivePath, PlanValidator $validator, Caps $caps): array
    {
        if ($plan->overrides === []) {
            return [];
        }

        $root = $plan->overrideRoot();
        $reader = PackArchiveReader::open($archivePath, $validator, $caps);
        $hashes = [];

        try {
            foreach ($plan->overrides as $override) {
                if ($validator->overrideTarget($override->entry, $root) !== $override->path) {
                    throw new UnsafePlanException('unsafe_path', $override->entry);
                }
                if ($validator->isProtected($override->path)) {
                    continue;
                }

                $contents = $reader->read($override->entry, $caps->maxOverrideBytes);
                $hashes[$override->path] = ['size' => strlen($contents), 'hash' => 'sha512:'.hash('sha512', $contents)];
            }
        } finally {
            $reader->close();
        }

        return $hashes;
    }

    /**
     * A stored manifest, re-checked, keyed by path: entries with an unsafe path, a malformed
     * hash or a negative size are dropped (the database is not trusted to hold only good
     * values). Null when there is no manifest at all.
     *
     * @return array<string, array{path: string, size: int, hash: string, kept: bool}>|null
     */
    public static function entries(mixed $manifest): ?array
    {
        if (!is_array($manifest)) {
            return null;
        }

        $entries = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_int($entry['size'] ?? null) || $entry['size'] < 0
                || !is_string($entry['hash'] ?? null) || self::splitHash($entry['hash']) === null
                || !PlanValidator::isSafeRelativePath($entry['path'])) {
                continue;
            }

            $entries[$entry['path']] = ['path' => $entry['path'], 'size' => $entry['size'], 'hash' => strtolower($entry['hash']), 'kept' => (bool) ($entry['kept'] ?? false)];
        }

        return $entries;
    }

    /**
     * `sha512:<hex>` as [algorithm, digest], or null when malformed.
     *
     * @return array{string, string}|null
     */
    public static function splitHash(string $hash): ?array
    {
        $parts = explode(':', strtolower($hash), 2);
        if (count($parts) !== 2 || !isset(self::ALGORITHMS[$parts[0]]) || !preg_match('/^[0-9a-f]{'.self::ALGORITHMS[$parts[0]].'}$/', $parts[1])) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /** Whether $contents has the manifest hash $hash. */
    public static function matches(string $hash, string $contents): bool
    {
        $split = self::splitHash($hash);

        return $split !== null && hash_equals($split[1], hash($split[0], $contents));
    }
}
