<?php

namespace DrMxrcy\ModpacksPlus\Providers;

use DrMxrcy\ModpacksPlus\Console\Commands\CheckPackUpdatesCommand;
use DrMxrcy\ModpacksPlus\Console\Commands\WatchInstallsCommand;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use DrMxrcy\ModpacksPlus\Services\DaemonServerPower;
use DrMxrcy\ModpacksPlus\Services\PackUpdateChecker;
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
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => self::schedule($schedule));
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command(WatchInstallsCommand::class)->everyMinute()->withoutOverlapping();

        // Plan 36: pack update checks, only when the admin turned them on.
        match (PackUpdateChecker::frequency()) {
            'daily' => $schedule->command(CheckPackUpdatesCommand::class)->daily()->withoutOverlapping(),
            'weekly' => $schedule->command(CheckPackUpdatesCommand::class)->weekly()->withoutOverlapping(),
            default => null,
        };
    }
}
