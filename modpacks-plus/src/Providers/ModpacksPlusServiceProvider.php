<?php

namespace DrMxrcy\ModpacksPlus\Providers;

use DrMxrcy\ModpacksPlus\Console\Commands\WatchInstallsCommand;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ModpacksPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServerFiles::class, DaemonServerFiles::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(WatchInstallsCommand::class)->everyMinute()->withoutOverlapping();
        });
    }
}
