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

        if ($install->preserved_path === null) {
            $install->preserved_path = PlanValidator::OWN_FOLDER.'/preserved-'.now()->format('Ymd-His').'-'.$install->id;
            $engine->persist($install);
        }
        $root = $install->preserved_path;

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
            // entry unknown to the restore (an entry that turns out absent is dropped below).
            if (!in_array($entry, $preserved, true)) {
                $preserved[] = $entry;
                $install->setProgress('preserved', $preserved);
                $engine->persist($install);
            }

            $result = $service->preserve($files, $server, $root, $entry);
            if ($result === PreservedFiles::RESULT_ABSENT) {
                $preserved = array_values(array_diff($preserved, [$entry]));
            }
            if ($result === PreservedFiles::RESULT_MOVED) {
                $install->addLog('info', 'preserved', ['path' => $entry, 'to' => "$root/$entry"]);
            }

            $checked[] = $entry;
            $install->setProgress('preserved', $preserved);
            $install->setProgress('preserve_checked', $checked);
            $engine->persist($install);
        }

        $install->addLog('info', 'preserved_all', ['count' => count($preserved), 'path' => $root]);

        return StepResult::done();
    }
}
