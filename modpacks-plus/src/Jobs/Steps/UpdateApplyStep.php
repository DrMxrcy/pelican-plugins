<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Update (plan 36): moves every file UpdateDiffStep decided to move (dropped by the new
 * version, replaced by it, or a non-pack file at a new path) into
 * `.modpacks-plus/previous-<date>-<id>/`, keeping its relative path. Nothing is deleted or
 * overwritten; worlds and player files can't be moved (the daemon adapter refuses protected
 * paths). Each path is recorded in progress.moved before it moves, so "Restore previous
 * files" knows it even after a crash. Idempotent: a path already moved is only aside.
 */
class UpdateApplyStep extends InstallStep
{
    public const KEY = 'update_apply';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        $validator = $engine->validatorFor($install);
        $moves = $install->progressValue('update_moves', []);
        $moves = is_array($moves) ? array_values(array_filter($moves, 'is_string')) : [];

        if ($install->moved_aside_path === null) {
            $install->moved_aside_path = PlanValidator::OWN_FOLDER.'/previous-'.now()->format('Ymd-His').'-'.$install->id;
            $engine->persist($install);
        }
        $aside = $install->moved_aside_path;

        $files = $this->files();
        $moved = $install->movedFolders();
        $done = $install->progressValue('update_moved', []);
        $done = is_array($done) ? $done : [];

        foreach ($moves as $path) {
            if (in_array($path, $done, true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return StepResult::again();
            }
            if (!PlanValidator::isSafeRelativePath($path) || $validator->isProtected($path)) {
                $install->addLog('warning', 'kept_protected', ['path' => $path]);
                $done[] = $path;

                continue;
            }

            $target = "$aside/$path";
            $atRoot = $files->stat($server, $path) !== null;
            $atTarget = $files->stat($server, $target) !== null;
            if ($atRoot && $atTarget) {
                throw new StepFailedException('move_aside_conflict', $target);
            }

            if ($atRoot) {
                // Recorded first: a crash right after the move can't leave it unknown to a restore.
                if (!in_array($path, $moved, true)) {
                    $moved[] = $path;
                    $install->setProgress('moved', $moved);
                    $engine->persist($install);
                }
                $files->createDirectory($server, dirname($target));
                $files->rename($server, $path, $target);
                $install->addLog('info', 'moved_aside', ['path' => $path, 'to' => $target]);
            }

            $done[] = $path;
            $install->setProgress('update_moved', $done);
            $engine->persist($install);
        }

        return StepResult::done();
    }
}
