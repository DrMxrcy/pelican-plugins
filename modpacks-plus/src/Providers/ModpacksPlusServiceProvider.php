<?php

namespace DrMxrcy\ModpacksPlus\Providers;

use DrMxrcy\ModpacksPlus\Console\Commands\WatchInstallsCommand;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use DrMxrcy\ModpacksPlus\Services\DaemonServerPower;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ModpacksPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServerFiles::class, DaemonServerFiles::class);
        $this->app->bind(ServerPower::class, DaemonServerPower::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(WatchInstallsCommand::class)->everyMinute()->withoutOverlapping();
        });
    }
}
