<?php

namespace DrMxrcy\PlayersPlus\Console\Commands;

use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Console\Command;

class PruneSamplesCommand extends Command
{
    protected $signature = 'players-plus:prune';

    protected $description = 'Delete Players Plus samples older than the retention setting';

    public function handle(): int
    {
        $deleted = Sample::query()->where('polled_at', '<', now()->subDays(Settings::retentionDays()))->delete();

        $this->info("Deleted $deleted sample(s).");

        return self::SUCCESS;
    }
}
