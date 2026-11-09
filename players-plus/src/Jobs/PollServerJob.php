<?php

namespace DrMxrcy\PlayersPlus\Jobs;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\PlayerPoller;
use DrMxrcy\PlayersPlus\Services\PollResult;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls one server for one poll slot. Short by design (a few seconds of socket time at most),
 * and idempotent: the row is keyed by (server, slot), so a retry or duplicate overwrites it.
 */
class PollServerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** The next poll is minutes away; retrying a stale poll is pointless. */
    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $serverId, public readonly int $slot) {}

    public function uniqueId(): string
    {
        return "$this->serverId:$this->slot";
    }

    public function handle(PlayerPoller $poller): void
    {
        // A backed-up queue must not write samples for slots that are already over.
        if (now()->getTimestamp() - $this->slot >= Settings::pollInterval() * 60) {
            return;
        }

        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        try {
            $result = $poller->poll($server, $this->slot);
        } catch (Throwable $exception) {
            $result = PollResult::unreachable($exception::class . ': ' . $exception->getMessage());
        }

        Sample::query()->updateOrCreate(
            ['server_id' => $server->id, 'polled_at' => Carbon::createFromTimestamp($this->slot)],
            [
                'status' => $result->status,
                'online' => $result->online,
                'max' => $result->max,
                'players' => $result->status === SampleStatus::Online ? $result->players : null,
                'source' => $result->source,
            ],
        );

        if ($result->status === SampleStatus::Unreachable) {
            self::reportFailure($server, (string) $result->error);
        }
    }

    /** At most one log line per server per hour, however often it fails. */
    public static function reportFailure(Server $server, string $error): void
    {
        if (Cache::add("players-plus.failure-logged.$server->id", true, 3600)) {
            Log::warning("Players Plus: server $server->uuid is running but did not answer: $error");
        }
    }
}
