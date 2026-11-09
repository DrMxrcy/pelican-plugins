<?php

namespace DrMxrcy\PlayersPlus\Jobs;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Services\LogBackfill;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans one server's logs/latest.log. Short (reading stops after LogReader::DEADLINE_SECONDS)
 * and idempotent (the cursor moves with the sessions, in one transaction). It never touches the
 * poll loop: the only shared state is the per-server tracking lock, held for one transaction.
 *
 * A failed read is not retried for FAILURE_BACKOFF seconds, so an unreachable node or an
 * oversized log costs one directory listing per backoff, not one per scan.
 */
class ScanLogsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const FAILURE_BACKOFF = 1800;

    /** The next scan is five minutes away. */
    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(LogBackfill $backfill): void
    {
        if (!Settings::logScan() || Cache::has("players-plus.log-scan-failed.$this->serverId")) {
            return;
        }

        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        try {
            $backfill->scan($server);
        } catch (FileNotFoundException) {
            // No log yet (never started, or a non-vanilla layout): try again next time.
        } catch (FileSizeTooLargeException) {
            Cache::put("players-plus.log-scan-failed.$this->serverId", true, self::FAILURE_BACKOFF);
        } catch (Throwable $exception) {
            Cache::put("players-plus.log-scan-failed.$this->serverId", true, self::FAILURE_BACKOFF);
            if (Cache::add("players-plus.log-scan-failure-logged.$this->serverId", true, 3600)) {
                Log::warning("Players Plus: log scan failed for server $server->uuid: " . $exception::class . ': ' . $exception->getMessage());
            }
        }
    }
}
