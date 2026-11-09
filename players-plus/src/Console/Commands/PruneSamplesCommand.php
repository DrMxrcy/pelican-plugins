<?php

namespace DrMxrcy\PlayersPlus\Console\Commands;

use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Console\Command;

class PruneSamplesCommand extends Command
{
    protected $signature = 'players-plus:prune';

    protected $description = 'Delete Players Plus samples and hourly rollups older than their retention settings';

    public function handle(): int
    {
        $samples = Sample::query()->where('polled_at', '<', now()->subDays(Settings::retentionDays()))->delete();
        $rollups = HourlyRollup::query()->where('hour_start', '<', now()->subDays(Settings::rollupRetentionDays()))->delete();

        $this->info("Deleted $samples sample(s) and $rollups hourly row(s).");

        return self::SUCCESS;
    }
}
