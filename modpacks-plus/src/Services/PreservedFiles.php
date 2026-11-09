<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Moves worlds and player files out of a reinstall's way (into `.modpacks-plus/…`) and
 * back, and puts old pack folders back on "Restore previous files". Every move goes
 * through ServerFiles::movePreserved(): between the root and the own folder only, never
 * replacing anything. Anything found in the way of a restore is itself moved aside into
 * a "replaced" folder, so no file is ever overwritten or deleted on any path.
 *
 * Each call is idempotent (decided by what is on disk), so a retried job just continues.
 */
final class PreservedFiles
{
    /** Kept besides the worlds and PlanValidator::PROTECTED_FILES (EULA acceptance). */
    public const EXTRA_FILES = ['eula.txt'];

    public const RESULT_MOVED = 'moved';

    public const RESULT_REPLACED = 'replaced';

    public const RESULT_ALREADY = 'already';

    public const RESULT_ABSENT = 'absent';

    /**
     * What a reinstall must not touch: each world with its `_nether`/`_the_end`, the
     * player lists, server.properties and eula.txt. Paths inside another candidate are
     * dropped (they move with it).
     *
     * @param  array<int, string>  $worlds
     * @return array<int, string>
     */
    public static function candidates(array $worlds): array
    {
        $paths = [];
        foreach ($worlds as $world) {
            foreach (['', '_nether', '_the_end'] as $suffix) {
                $paths[] = $world.$suffix;
            }
        }
        $paths = array_values(array_unique(array_merge($paths, PlanValidator::PROTECTED_FILES, self::EXTRA_FILES)));

        return array_values(array_filter($paths, function (string $path) use ($paths) {
            foreach ($paths as $other) {
                if ($other !== $path && str_starts_with($path, $other.'/')) {
                    return false;
                }
            }

            return PlanValidator::isSafeRelativePath($path);
        }));
    }

    /**
     * The recorded entries plus any expected entry (a candidate for these worlds) found in
     * the preserved folder although the record lacks it: the database and the disk
     * reconciled. Nothing else in the folder is ever moved (a name planted there, e.g.
     * `.modpacks-plus`, could otherwise block every restore); it is reported as unexpected
     * and left in place.
     *
     * @param  array<int, string>  $entries
     * @param  array<int, string>  $worlds
     * @return array{entries: array<int, string>, unexpected: array<int, string>}
     */
    public function reconcile(ServerFiles $files, Server $server, string $root, array $entries, array $worlds): array
    {
        $candidates = self::candidates($worlds);

        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $entries, true) && $files->stat($server, "$root/$candidate") !== null) {
                $entries[] = $candidate;
            }
        }

        $known = array_map(fn (string $path) => explode('/', $path)[0], array_merge($entries, $candidates));
        $unexpected = [];
        foreach (array_keys($files->list($server, $root) ?? []) as $name) {
            if (!in_array((string) $name, $known, true)) {
                $unexpected[] = (string) $name;
            }
        }

        return ['entries' => $entries, 'unexpected' => $unexpected];
    }

    /**
     * Moves $entry (relative to the root) to $root/$entry.
     *
     * $beforeMove runs once the entry is known to exist (at the root, or already in $root on a
     * retry) and before anything moves: the caller records it there.
     *
     * @param  (\Closure(): void)|null  $beforeMove
     * @return string moved | already (only in $root, a retry) | absent (nowhere)
     *
     * @throws StepFailedException `preserve_conflict` when both places hold it
     */
    public function preserve(ServerFiles $files, Server $server, string $root, string $entry, ?\Closure $beforeMove = null): string
    {
        $target = "$root/$entry";
        $atRoot = $files->stat($server, $entry) !== null;
        $aside = $files->stat($server, $target) !== null;

        if ($atRoot && $aside) {
            throw new StepFailedException('preserve_conflict', $target);
        }
        if (!$atRoot && !$aside) {
            return self::RESULT_ABSENT;
        }

        if ($beforeMove !== null) {
            $beforeMove();
        }
        if (!$atRoot) {
            return self::RESULT_ALREADY;
        }

        $files->createDirectory($server, dirname($target));
        $files->movePreserved($server, $entry, $target);

        return self::RESULT_MOVED;
    }

    /**
     * Puts $root/$entry back at $entry. Something already at $entry (made by the reinstall,
     * the new pack or a server start) is first moved to $replaced/$entry.
     *
     * @return string moved | replaced (moved back, the newer one kept in $replaced) | already | absent
     *
     * @throws StepFailedException `restore_conflict` when $replaced/$entry is taken too
     */
    public function restore(ServerFiles $files, Server $server, string $root, string $entry, string $replaced): string
    {
        $source = "$root/$entry";
        if ($files->stat($server, $source) === null) {
            return $files->stat($server, $entry) !== null ? self::RESULT_ALREADY : self::RESULT_ABSENT;
        }

        $result = self::RESULT_MOVED;
        if ($files->stat($server, $entry) !== null) {
            $keep = "$replaced/$entry";
            if ($files->stat($server, $keep) !== null) {
                throw new StepFailedException('restore_conflict', $keep);
            }
            $files->createDirectory($server, dirname($keep));
            $files->movePreserved($server, $entry, $keep);
            $result = self::RESULT_REPLACED;
        }

        $parent = dirname($entry);
        if ($parent !== '.') {
            $files->createDirectory($server, $parent);
        }
        $files->movePreserved($server, $source, $entry);

        return $result;
    }
}
