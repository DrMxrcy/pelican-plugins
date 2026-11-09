<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PreservedFiles;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Loader switch: stops the server, then moves the worlds, player lists,
 * server.properties and eula.txt into `.modpacks-plus/preserved-<date>-<id>/` so the
 * reinstall's script cannot touch them. Each entry is recorded before it moves (progress.preserved),
 * which is what RestorePreservedStep and "Restore previous files" put back. Idempotent:
 * an entry already moved is only in the preserved folder.
 */
class PreserveStep extends InstallStep
{
    public const KEY = 'preserve';

    /** Cancellable until the first file has been set aside. */
    public static function cancellableAt(PackInstall $install): bool
    {
        return $install->preserved_path === null;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        // The folder name is fixed up front, but only becomes the install's preserved_path
        // right before the first real move: until then nothing is set aside, nothing is
        // pending and the step can still be cancelled.
        $root = $install->preserved_path ?? $install->progressValue('preserved_dir');
        if (!is_string($root)) {
            $root = PlanValidator::OWN_FOLDER.'/preserved-'.now()->format('Ymd-His').'-'.$install->id;
            $install->setProgress('preserved_dir', $root);
            $engine->persist($install);
        }

        $worlds = $install->progressValue('worlds', ['world']);
        $worlds = is_array($worlds) ? array_values(array_filter($worlds, 'is_string')) : ['world'];

        $service = new PreservedFiles();
        $files = $this->files();
        $preserved = $install->preservedEntries();
        $checked = $install->progressValue('preserve_checked', []);

        foreach (PreservedFiles::candidates($worlds) as $entry) {
            if (in_array($entry, $checked, true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return StepResult::again();
            }

            // Recorded before the move: a crash right after it can never leave a moved
            // entry unknown to the restore.
            $result = $service->preserve($files, $server, $root, $entry, function () use ($install, $engine, $root, $entry, &$preserved) {
                $install->preserved_path ??= $root;
                if (!in_array($entry, $preserved, true)) {
                    $preserved[] = $entry;
                }
                $install->setProgress('preserved', $preserved);
                $engine->persist($install);
            });
            if ($result === PreservedFiles::RESULT_MOVED) {
                $install->addLog('info', 'preserved', ['path' => $entry, 'to' => "$root/$entry"]);
            }

            $checked[] = $entry;
            $install->setProgress('preserve_checked', $checked);
            $engine->persist($install);
        }

        $install->addLog('info', 'preserved_all', ['count' => count($preserved), 'path' => $root]);

        return StepResult::done();
    }
}
