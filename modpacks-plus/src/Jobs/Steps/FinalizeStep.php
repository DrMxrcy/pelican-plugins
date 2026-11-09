<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;

/** Marks the install done; the engine writes the summary and removes the panel copy of the archive. */
class FinalizeStep extends InstallStep
{
    public const KEY = 'finalize';

    protected const TOUCHES_FILES = false;

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        return StepResult::done();
    }
}
