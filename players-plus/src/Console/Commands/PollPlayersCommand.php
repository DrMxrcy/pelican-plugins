<?php

namespace DrMxrcy\PlayersPlus\Console\Commands;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Jobs\PollServerJob;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Dispatches one PollServerJob per Minecraft server. It never contacts a game server or the
 * daemon itself, so one slow server can't hold up the others: each runs in its own job.
 */
class PollPlayersCommand extends Command
{
    protected $signature = 'players-plus:poll';

    protected $description = 'Queue a player poll for every Minecraft server';

    public function handle(): int
    {
        if (!Settings::enabled()) {
            $this->info('Players Plus polling is disabled.');

            return self::SUCCESS;
        }

        $slot = self::slot(now()->getTimestamp(), Settings::pollInterval());
        $dispatched = 0;

        Server::query()->with('egg')->chunkById(200, function (Collection $servers) use ($slot, &$dispatched) {
            foreach ($servers as $server) {
                /** @var Server $server */
                if (Minecraft::isMinecraft($server)) {
                    PollServerJob::dispatch($server->id, $slot);
                    $dispatched++;
                }
            }
        });

        $this->info("Queued $dispatched server poll(s).");

        return self::SUCCESS;
    }

    /** Start of the poll slot (whole multiples of the interval) that $timestamp falls in. */
    public static function slot(int $timestamp, int $intervalMinutes): int
    {
        $length = $intervalMinutes * 60;

        return intdiv($timestamp, $length) * $length;
    }
}
