<?php

namespace DrMxrcy\PlayersPlus\Providers;

use App\Models\Role;
use DrMxrcy\PlayersPlus\Console\Commands\PollPlayersCommand;
use DrMxrcy\PlayersPlus\Console\Commands\PruneSamplesCommand;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Console\Commands\ScanLogsCommand;
use DrMxrcy\PlayersPlus\Filament\Admin\Pages\PlayersOverviewPage;
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
        // Admin overview (plan 34): "View" under "Players Plus" in the role editor.
        Role::registerCustomPermissions([PlayersOverviewPage::PERMISSION_MODEL => ['view']]);
        Role::registerCustomModelIcon(PlayersOverviewPage::PERMISSION_MODEL, 'tabler-users');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PollPlayersCommand::class)
                ->cron(sprintf('*/%d * * * *', Settings::pollInterval()))
                ->withoutOverlapping(10);

            // Previous hour (now complete) and the current one; idempotent, so reruns are harmless.
            $schedule->command(RollupCommand::class)
                ->hourlyAt(5)
                ->withoutOverlapping(10);

            $schedule->command(ScanLogsCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping(10);

            $schedule->command(PruneSamplesCommand::class)
                ->dailyAt('04:20')
                ->withoutOverlapping(10);
        });
    }
}
