<?php

namespace DrMxrcy\ModpacksPlus\Console\Commands;

use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use Illuminate\Console\Command;

/** Scheduled every minute: no install stays "installing" past its step deadline or with a lost job chain. */
class WatchInstallsCommand extends Command
{
    protected $signature = 'modpacks-plus:watch';

    protected $description = 'Fail Modpacks Plus installs past their step deadline and resume stalled ones';

    public function handle(InstallEngine $engine): int
    {
        [$failed, $resumed] = $engine->watchdog();

        if ($failed > 0 || $resumed > 0) {
            $this->info("Modpacks Plus: $failed ended, $resumed resumed");
        }

        return self::SUCCESS;
    }
}
