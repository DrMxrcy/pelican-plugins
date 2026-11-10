<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PreservedFiles;
use DrMxrcy\ModpacksPlus\Support\DirectoryListings;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Stops the server (plan 47), then moves the previous pack's folders into
 * `.modpacks-plus/previous-<date>-<id>/`. Nothing is deleted. A folder that is (or contains)
 * a world or another protected path is never moved. Idempotent: a folder already moved is not
 * at the root anymore.
 *
 * Plan 36, replacing a recorded pack (a switch, or the same pack again): the files the old
 * install's manifest lists outside those folders go aside too. "Start a fresh world": the
 * world (`level-name` with its `_nether`/`_the_end`) is moved aside the one way a protected path may move, ServerFiles::movePreserved(), never deleted.
 * Every such entry is recorded in progress.moved before it moves, so "Restore previous files"
 * puts it back after a failure or cancel even if the worker died mid-move.
 */
class MoveAsideStep extends InstallStep
{
    public const KEY = 'move_aside';

    public const FOLDERS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts'];

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $freshWorld = $install->option('fresh_world');
        // Plan 47: every install stops the server before anything moves, in every mode (even
        // with no pack folders to move: the next steps write mods/ and config/). The old pack must
        // not keep running while its folders move, nor save its config on shutdown on top of the
        // new pack's files; a running server also writes its world back (fresh world).
        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        $validator = $engine->validatorFor($install);
        $files = $this->files();

        if ($install->moved_aside_path === null) {
            $install->moved_aside_path = PlanValidator::OWN_FOLDER.'/previous-'.now()->format('Ymd-His').'-'.$install->id;
            $engine->persist($install);
        }
        $aside = $install->moved_aside_path;

        $files->createDirectory($server, $aside);

        $root = $files->list($server, '') ?? [];
        $asideEntries = $files->list($server, $aside) ?? [];
        $moved = $install->progressValue('moved', []);

        foreach (self::FOLDERS as $folder) {
            if (!isset($root[$folder])) {
                continue;
            }

            if ($validator->isProtected($folder)) {
                $install->addLog('warning', 'kept_protected', ['path' => $folder]);

                continue;
            }

            if (isset($asideEntries[$folder])) {
                throw new StepFailedException('move_aside_conflict', "$aside/$folder");
            }

            $files->rename($server, $folder, "$aside/$folder");
            $moved[] = $folder;
            $install->setProgress('moved', array_values(array_unique($moved)));
            $install->addLog('info', 'moved_aside', ['path' => $folder, 'to' => "$aside/$folder"]);
            $engine->persist($install);
        }

        $result = $this->moveRecordedFiles($install, $server, $engine, $aside);
        if ($result !== null) {
            return $result;
        }

        if ($freshWorld) {
            $this->moveWorld($install, $server, $engine, $aside);
        }

        return StepResult::done();
    }

    /**
     * The previous install's files outside FOLDERS (those moved as a whole), by its manifest.
     * Protected paths are never touched; a file already gone is skipped.
     *
     * Plan 44: up to ServerFiles::RENAME_BATCH files per daemon request, decided from one
     * listing per directory. A batch is recorded in progress.moved before it moves; after a
     * killed worker the files already aside are simply gone from the root (skipped). A batch
     * the node refuses goes per file, as before.
     */
    private function moveRecordedFiles(PackInstall $install, Server $server, InstallEngine $engine, string $aside): ?StepResult
    {
        // A version change (plan 40) moves only the pack folders: the pack stays the installed
        // one, so its other files (server icon, menus) stay where its record says they are.
        if ($install->progressValue('recorded_moved') || $install->previous_install_id === null || $install->mode === PackInstall::MODE_VERSION) {
            return null;
        }

        $previous = PackInstall::query()->whereKey($install->previous_install_id)->where('server_id', $server->id)->first();
        $done = $install->progressValue('recorded_done', []);
        $done = is_array($done) ? $done : [];
        $known = array_flip(array_filter($done, 'is_string'));

        $pending = [];
        foreach (array_keys($previous?->manifestEntries() ?? []) as $path) {
            $path = (string) $path;
            if (!in_array(explode('/', $path)[0], self::FOLDERS, true) && !isset($known[$path])) {
                $pending[] = $path;
            }
        }

        foreach (array_chunk($pending, ServerFiles::RENAME_BATCH) as $chunk) {
            if ($this->outOfTime() || JobClock::exhausted()) {
                $install->setProgress('recorded_done', $done);
                $engine->persist($install);

                return StepResult::again();
            }

            $this->moveBatch($install, $server, $engine, $aside, $chunk);
            $done = array_merge($done, $chunk);
            $install->setProgress('recorded_done', $done);
            $engine->persist($install);
        }

        $install->setProgress('recorded_moved', true);
        $engine->persist($install);

        return null;
    }

    /**
     * One batch of recorded files: folders kept, conflicts refused, the rest moved in one request
     * (the world's companions, eula.txt and other preserved names one by one, never in a batch).
     *
     * @param  array<int, string>  $paths
     */
    private function moveBatch(PackInstall $install, Server $server, InstallEngine $engine, string $aside, array $paths): void
    {
        $validator = $engine->validatorFor($install);
        $files = $this->files();
        $listings = new DirectoryListings($files, $server);
        $worlds = $install->progressValue('worlds', ['world']);
        $preserved = PreservedFiles::candidates(array_values(array_unique(array_merge(['world'], is_array($worlds) ? array_filter($worlds, 'is_string') : []))));

        /** @var array<string, string> $batch path => target */
        $batch = [];
        /** @var array<string, string> $single path => target */
        $single = [];
        /** @var array<int, string> $order every path to move, in manifest order */
        $order = [];
        foreach ($paths as $path) {
            $stat = $validator->isProtected($path) ? null : $listings->stat($path);
            if ($stat !== null && $stat['directory']) {
                // The old pack listed a file here; a folder now is the user's: never moved whole.
                $kept = $install->progressValue('kept_folders', []);
                $kept = is_array($kept) ? $kept : [];
                $kept[] = $path;
                $install->setProgress('kept_folders', array_values(array_unique($kept)));
                $install->addLog('warning', 'kept_folder', ['path' => $path]);
            } elseif ($stat !== null) {
                $target = "$aside/$path";
                if ($listings->stat($target) !== null) {
                    throw new StepFailedException('move_aside_conflict', $target);
                }
                $order[] = $path;
                if (self::isPreserved($path, $preserved)) {
                    $single[$path] = $target;
                } else {
                    $batch[$path] = $target;
                }
            }
        }
        if ($order === []) {
            return;
        }

        // Recorded before anything moves, for the summary and for "Restore previous files".
        $install->setProgress('moved', array_values(array_unique(array_merge($install->movedFolders(), $order))));
        $engine->persist($install);

        $listings->createParents(array_values(array_merge($batch, $single)));

        if ($batch !== []) {
            try {
                $files->renameMany($server, array_map(fn (string $from, string $to) => ['from' => $from, 'to' => $to], array_keys($batch), array_values($batch)));
                $install->addLog('info', 'moved_aside_batch', ['count' => count($batch), 'to' => $aside]);
            } catch (UnsafePlanException $exception) {
                throw $exception;
            } catch (RuntimeException|RequestException) {
                // Refused (a target appeared, one source failed): what moved stays moved, the rest one by one.
                $single = $batch + $single;
            }
        }

        foreach ($single as $path => $target) {
            if ($files->stat($server, $path) === null) {
                continue; // Moved by the refused batch, or gone meanwhile.
            }
            if ($files->stat($server, $target) !== null) {
                throw new StepFailedException('move_aside_conflict', $target);
            }
            $files->rename($server, $path, $target);
            $install->addLog('info', 'moved_aside', ['path' => $path, 'to' => $target]);
        }
    }

    /**
     * A world with its other dimensions, a player list, server.properties or eula.txt (or a
     * path inside one): never part of a batch.
     *
     * @param  array<int, string>  $preserved
     */
    private static function isPreserved(string $path, array $preserved): bool
    {
        $lower = strtolower($path);
        foreach ($preserved as $candidate) {
            $candidate = strtolower($candidate);
            if ($lower === $candidate || str_starts_with($lower, $candidate.'/') || str_starts_with($candidate, $lower.'/')) {
                return true;
            }
        }

        return false;
    }

    /** "Start a fresh world": the world and its other dimensions aside, the player lists stay. */
    private function moveWorld(PackInstall $install, Server $server, InstallEngine $engine, string $aside): void
    {
        $worlds = $install->progressValue('worlds', ['world']);
        $levelName = is_array($worlds) && is_string($worlds[0] ?? null) ? $worlds[0] : 'world';
        $files = $this->files();
        $movedWorlds = $install->progressValue('fresh_world_moved', []);
        $movedWorlds = is_array($movedWorlds) ? $movedWorlds : [];

        foreach (['', '_nether', '_the_end'] as $suffix) {
            $world = $levelName.$suffix;
            if (!PlanValidator::isSafeRelativePath($world)) {
                continue;
            }

            $target = "$aside/$world";
            $atRoot = $files->stat($server, $world) !== null;
            $atTarget = $files->stat($server, $target) !== null;
            if ($atRoot && $atTarget) {
                throw new StepFailedException('move_aside_conflict', $target);
            }
            if (!$atRoot && !$atTarget) {
                continue;
            }

            // Recorded before it moves, for the summary and for "Restore previous files".
            if (!in_array($world, $movedWorlds, true)) {
                $movedWorlds[] = $world;
                $install->setProgress('fresh_world_moved', $movedWorlds);
                $this->record($install, $engine, $world);
            }

            if ($atRoot) {
                $files->createDirectory($server, dirname($target));
                $files->movePreserved($server, $world, $target);
                $install->addLog('info', 'fresh_world', ['path' => $world, 'to' => $target]);
            }
        }
    }

    private function record(PackInstall $install, InstallEngine $engine, string $path): void
    {
        $moved = $install->movedFolders();
        if (!in_array($path, $moved, true)) {
            $moved[] = $path;
            $install->setProgress('moved', $moved);
        }
        $engine->persist($install);
    }
}
