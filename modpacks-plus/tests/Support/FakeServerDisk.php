<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Services\ServerDiskUsage;

/** The node's disk usage report (plan 38): null = the node couldn't say. Counts calls. */
final class FakeServerDisk extends ServerDiskUsage
{
    public ?int $used = 0;

    public int $calls = 0;

    public function usedBytes(Server $server, int $timeout = 5): ?int
    {
        $this->calls++;

        return $this->used;
    }
}
