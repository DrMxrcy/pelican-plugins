<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;

/**
 * The server's current disk usage as the node reports it (`utilization.disk_bytes` of the
 * daemon's server details), one uncached call with a short timeout. Null when the node
 * couldn't say (unreachable, error, maintenance, missing field): callers fall back to the
 * configured cap and never treat that as unlimited (plan 38).
 */
class ServerDiskUsage
{
    public function usedBytes(Server $server, int $timeout = 5): ?int
    {
        try {
            if ($server->node->isUnderMaintenance()) {
                return null;
            }

            $details = app(DaemonServerRepository::class)->setServer($server)->getDetails($timeout);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $used = is_array($details['utilization'] ?? null) ? ($details['utilization']['disk_bytes'] ?? null) : null;
        if (is_float($used) && is_finite($used)) {
            $used = (int) $used;
        }

        return is_int($used) && $used >= 0 ? $used : null;
    }
}
