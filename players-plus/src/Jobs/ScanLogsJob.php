<?php

namespace DrMxrcy\PlayersPlus\Jobs;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Services\LogBackfill;
use DrMxrcy\PlayersPlus\Services\LogReadTooSlow;
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
 * Scans one server's logs/latest.log, well inside the 55 s timeout. Worst case, in order:
 * - read: about 16 s (LogReader::DEADLINE_SECONDS = 15 covers the listing and the stream; the
 *   download's own timeout is what is left of it, at least 1 s);
 * - usercache: at most 2 x 4 s (LogBackfill::USERCACHE_TIMEOUT_SECONDS, listing + download),
 *   only when a log name has no UUID and fewer than 20 s have passed, else skipped;
 * - tracking lock wait: 5 s (LogBackfill::$lockWaitSeconds);
 * - merge: 10 s (LogBackfill::MERGE_SECONDS) plus the event in progress, then one commit.
 * About 40 s in total. Idempotent: the cursor moves with the sessions, in one transaction.
 * It never touches the poll loop: the only shared state is the per-server tracking lock, held
 * for one transaction.
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

    /** Shorter than the 5-minute schedule, so a lost lock never skips the next scan. */
    public int $uniqueFor = 240;

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
        } catch (FileSizeTooLargeException|LogReadTooSlow) {
            // Too big, or the node streams it too slowly to reach the cursor: back off quietly.
            Cache::put("players-plus.log-scan-failed.$this->serverId", true, self::FAILURE_BACKOFF);
        } catch (Throwable $exception) {
            Cache::put("players-plus.log-scan-failed.$this->serverId", true, self::FAILURE_BACKOFF);
            if (Cache::add("players-plus.log-scan-failure-logged.$this->serverId", true, 3600)) {
                Log::warning("Players Plus: log scan failed for server $server->uuid: " . $exception::class . ': ' . $exception->getMessage());
            }
        }
    }
}
