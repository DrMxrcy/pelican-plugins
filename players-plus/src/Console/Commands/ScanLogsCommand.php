<?php

namespace DrMxrcy\PlayersPlus\Console\Commands;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Jobs\ScanLogsJob;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Dispatches one ScanLogsJob per Minecraft server. It never contacts the daemon itself, so
 * one slow node can't hold up the others. Stopped servers are scanned too: their log still
 * holds the leave lines written on shutdown.
 */
class ScanLogsCommand extends Command
{
    protected $signature = 'players-plus:scan-logs';

    protected $description = 'Queue a join/leave log scan for every Minecraft server';

    public function handle(): int
    {
        if (!Settings::logScan()) {
            $this->info('Players Plus log scanning is disabled.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        Server::query()->with('egg')->chunkById(200, function (Collection $servers) use (&$dispatched) {
            foreach ($servers as $server) {
                /** @var Server $server */
                if (Minecraft::isMinecraft($server)) {
                    ScanLogsJob::dispatch($server->id);
                    $dispatched++;
                }
            }
        });

        $this->info("Queued $dispatched log scan(s).");

        return self::SUCCESS;
    }
}
