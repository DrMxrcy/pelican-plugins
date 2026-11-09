<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;

/**
 * Removes the (now empty) staging folder, best effort, and marks the install done; the
 * engine writes the summary and removes the panel copy of the archive.
 */
class FinalizeStep extends InstallStep
{
    public const KEY = 'finalize';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $staging = DownloadStep::stagingRoot($install);

        try {
            if ($this->files()->stat($server, $staging) !== null) {
                $this->files()->delete($server, $staging);
            }
        } catch (\Throwable) {
            $install->addLog('warning', 'leftover', ['path' => $staging]);
        }

        return StepResult::done();
    }
}
