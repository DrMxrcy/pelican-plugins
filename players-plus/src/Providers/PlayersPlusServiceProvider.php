<?php

namespace DrMxrcy\PlayersPlus\Providers;

use DrMxrcy\PlayersPlus\Console\Commands\PollPlayersCommand;
use DrMxrcy\PlayersPlus\Console\Commands\PruneSamplesCommand;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Services\Query\SocketConnector;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class PlayersPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Connector::class, SocketConnector::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PollPlayersCommand::class)
                ->cron(sprintf('*/%d * * * *', Settings::pollInterval()))
                ->withoutOverlapping();

            $schedule->command(PruneSamplesCommand::class)
                ->dailyAt('04:20')
                ->withoutOverlapping();
        });
    }
}
