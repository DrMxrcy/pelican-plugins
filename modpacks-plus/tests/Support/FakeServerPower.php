<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;

/** The server process as the node reports it; stopping takes effect when the test says so. */
final class FakeServerPower implements ServerPower
{
    public string $state = 'offline';

    public int $stops = 0;

    /** State after a stop request (null: unchanged until the test changes it). */
    public ?string $afterStop = null;

    public function state(Server $server): string
    {
        return $this->state;
    }

    public function stop(Server $server): void
    {
        $this->stops++;
        if ($this->afterStop !== null) {
            $this->state = $this->afterStop;
        }
    }
}
