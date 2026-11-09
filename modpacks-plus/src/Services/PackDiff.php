<?php

namespace DrMxrcy\ModpacksPlus\Services;

/**
 * What an update does to each file (plan 36). Pure: the caller gathers what is on the server.
 *
 * Inputs are installed-files manifests keyed by path (InstallManifest::entries()): the one
 * recorded by the installed version ($old) and the one the new version will have ($new), plus
 * the server's state of every path in either ($disk: null = absent, else its size and, when
 * the caller read it, its hash in the old entry's algorithm).
 *
 * Per path:
 * - kind: added (only new), removed (only old), changed (both, other hash), unchanged (same hash);
 * - modified: the server's file differs from what the old version installed (size, else hash);
 * - action: write (nothing in the way), aside_write (move what is there aside, then write),
 *   aside (move aside, write nothing), keep (leave it, it is already right), keep_modified (the
 *   user's file stays and the pack's is not written), none (nothing there, nothing to write).
 *
 * Nothing is ever deleted: "aside" means `.modpacks-plus/previous-<date>/`. A user-modified file
 * is only moved (never overwritten) and only when the admin ticked "overwrite my changes";
 * otherwise it is kept and reported. A file at an added path is not from the pack: it is moved
 * aside (the plan 30 rule for files in the way).
 */
final class PackDiff
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    public const WRITE = 'write';

    public const ASIDE_WRITE = 'aside_write';

    public const ASIDE = 'aside';

    public const KEEP = 'keep';

    public const KEEP_MODIFIED = 'keep_modified';

    public const NONE = 'none';

    /**
     * @param  array<string, array{path: string, size: int, hash: string, kept?: bool}>  $old
     * @param  array<string, array{path: string, size: int, hash: string, kept?: bool}>  $new
     * @param  array<string, array{size: int, hash: ?string}|null>  $disk
     * @return array<string, array{path: string, kind: string, modified: bool, action: string}> by path, sorted
     */
    public static function plan(array $old, array $new, array $disk, bool $overwrite): array
    {
        $paths = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
        sort($paths, SORT_STRING);

        $decisions = [];
        foreach ($paths as $path) {
            $before = $old[$path] ?? null;
            $after = $new[$path] ?? null;
            $state = $disk[$path] ?? null;

            $kind = match (true) {
                $before === null => self::ADDED,
                $after === null => self::REMOVED,
                strtolower($before['hash']) !== strtolower($after['hash']) || $before['size'] !== $after['size'] => self::CHANGED,
                default => self::UNCHANGED,
            };

            $modified = $before !== null && $state !== null && self::isModified($before, $state);

            $action = match (true) {
                $state === null => $after === null ? self::NONE : self::WRITE,
                $kind === self::ADDED => self::ASIDE_WRITE,
                $modified && !$overwrite => self::KEEP_MODIFIED,
                $kind === self::UNCHANGED => $modified ? self::ASIDE_WRITE : self::KEEP,
                $kind === self::REMOVED => self::ASIDE,
                default => self::ASIDE_WRITE,
            };

            $decisions[$path] = ['path' => $path, 'kind' => $kind, 'modified' => $modified, 'action' => $action];
        }

        return $decisions;
    }

    /**
     * Whether the server's file differs from the manifest entry: another size, or (when the
     * caller hashed it) another hash.
     *
     * @param  array{size: int, hash: string}  $entry
     * @param  array{size: int, hash: ?string}  $state
     */
    public static function isModified(array $entry, array $state): bool
    {
        if ($state['size'] !== $entry['size']) {
            return true;
        }

        return $state['hash'] !== null && strtolower($state['hash']) !== strtolower($entry['hash']);
    }

    /**
     * Paths whose server state needs a hash to decide: present at the old size and about to be
     * moved (changed or removed). Unchanged files are judged by size only: a same-size edit of
     * one is kept anyway, because unchanged files are never rewritten.
     *
     * @param  array<string, array{path: string, size: int, hash: string}>  $old
     * @param  array<string, array{path: string, size: int, hash: string}>  $new
     * @param  array<string, array{size: int, hash: ?string}|null>  $disk  sizes only
     * @return array<int, string>
     */
    public static function needsHash(array $old, array $new, array $disk): array
    {
        $paths = [];
        foreach ($old as $path => $entry) {
            $state = $disk[$path] ?? null;
            $after = $new[$path] ?? null;
            $moving = $after === null || strtolower($after['hash']) !== strtolower($entry['hash']) || $after['size'] !== $entry['size'];
            if ($state !== null && $state['size'] === $entry['size'] && $moving) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    /**
     * Counts and path lists for the summary.
     *
     * @param  array<string, array{path: string, kind: string, modified: bool, action: string}>  $decisions
     * @return array{added: int, changed: int, removed: int, unchanged: int, kept_modified: array<int, string>, overwritten: array<int, string>, removed_paths: array<int, string>, not_ours: array<int, string>}
     */
    public static function summary(array $decisions): array
    {
        $summary = ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0, 'kept_modified' => [], 'overwritten' => [], 'removed_paths' => [], 'not_ours' => []];
        foreach ($decisions as $decision) {
            $summary[$decision['kind']]++;
            if ($decision['action'] === self::KEEP_MODIFIED) {
                $summary['kept_modified'][] = $decision['path'];
            } elseif ($decision['modified'] && in_array($decision['action'], [self::ASIDE, self::ASIDE_WRITE], true)) {
                $summary['overwritten'][] = $decision['path'];
            }
            if ($decision['kind'] === self::REMOVED && $decision['action'] === self::ASIDE) {
                $summary['removed_paths'][] = $decision['path'];
            }
            if ($decision['kind'] === self::ADDED && $decision['action'] === self::ASIDE_WRITE) {
                $summary['not_ours'][] = $decision['path'];
            }
        }

        return $summary;
    }
}
