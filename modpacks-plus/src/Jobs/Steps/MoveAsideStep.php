<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Moves the previous pack's folders into `.modpacks-plus/previous-<date>-<id>/`.
 * Nothing is deleted. A folder that is (or contains) a world or another protected
 * path is never moved. Idempotent: a folder already moved is not at the root anymore.
 *
 * Plan 36, replacing a recorded pack (a switch, or the same pack again): the files the old
 * install's manifest lists outside those folders go aside too. "Start a fresh world": the
 * server is stopped first, then the world (`level-name` with its `_nether`/`_the_end`) is moved
 * aside the one way a protected path may move, ServerFiles::movePreserved(), never deleted.
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
        // A running server writes its world back: the world only moves once it is stopped.
        if ($freshWorld && !$this->ensureStopped($install, $server, $engine)) {
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
     */
    private function moveRecordedFiles(PackInstall $install, Server $server, InstallEngine $engine, string $aside): ?StepResult
    {
        if ($install->progressValue('recorded_moved') || $install->previous_install_id === null) {
            return null;
        }

        $previous = PackInstall::query()->whereKey($install->previous_install_id)->where('server_id', $server->id)->first();
        $validator = $engine->validatorFor($install);
        $files = $this->files();
        $done = $install->progressValue('recorded_done', []);
        $done = is_array($done) ? $done : [];

        foreach (array_keys($previous?->manifestEntries() ?? []) as $path) {
            $path = (string) $path;
            if (in_array(explode('/', $path)[0], self::FOLDERS, true) || in_array($path, $done, true)) {
                continue;
            }
            if ($this->outOfTime()) {
                $install->setProgress('recorded_done', $done);

                return StepResult::again();
            }

            if (!$validator->isProtected($path) && $files->stat($server, $path) !== null) {
                $target = "$aside/$path";
                if ($files->stat($server, $target) !== null) {
                    throw new StepFailedException('move_aside_conflict', $target);
                }
                $this->record($install, $engine, $path);
                $files->createDirectory($server, dirname($target));
                $files->rename($server, $path, $target);
                $install->addLog('info', 'moved_aside', ['path' => $path, 'to' => $target]);
            }

            $done[] = $path;
            $install->setProgress('recorded_done', $done);
            $engine->persist($install);
        }

        $install->setProgress('recorded_moved', true);
        $engine->persist($install);

        return null;
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
