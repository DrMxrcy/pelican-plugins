<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PreservedFiles;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Loader switch, after a successful reinstall: stops the server if it was started,
 * then puts the worlds and player files back from the preserved folder. Anything the
 * reinstall or a server start created in their place is moved to
 * `.modpacks-plus/replaced-<date>-<id>/` (kept, never overwritten). Idempotent.
 */
class RestorePreservedStep extends InstallStep
{
    public const KEY = 'restore_preserved';

    public static function cancellableAt(PackInstall $install): bool
    {
        return false;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->progressValue('preserved_restored')) {
            return StepResult::done();
        }

        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        $replaced = $install->progressValue('replaced_path');
        if (!is_string($replaced)) {
            $replaced = PlanValidator::OWN_FOLDER.'/replaced-'.now()->format('Ymd-His').'-'.$install->id;
            $install->setProgress('replaced_path', $replaced);
            $engine->persist($install);
        }

        $root = (string) $install->preserved_path;
        $service = new PreservedFiles();
        $files = $this->files();
        $done = $install->progressValue('preserved_back', []);

        foreach ($install->preservedEntries() as $entry) {
            if (in_array($entry, $done, true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return StepResult::again();
            }

            $result = $service->restore($files, $server, $root, $entry, $replaced);
            RestoreLog::add($install, $result, $entry, $replaced);

            $done[] = $entry;
            $install->setProgress('preserved_back', $done);
            $engine->persist($install);
        }

        $install->setProgress('preserved_restored', true);
        $install->addLog('info', 'preserved_restored', ['count' => count($done)]);

        return StepResult::done();
    }
}
