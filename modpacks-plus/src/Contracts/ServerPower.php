<?php

namespace DrMxrcy\ModpacksPlus\Contracts;

use App\Models\Server;

/**
 * The server process, as far as moving worlds needs it: a running server writes its
 * world by path, so worlds are only moved (aside or back) while it is stopped.
 */
interface ServerPower
{
    /** States in which the server process is not running. */
    public const STOPPED = ['offline', 'exited', 'dead', 'created'];

    /** The container state the node reports (offline, running, starting, stopping, …); `missing` when unknown. */
    public function state(Server $server): string;

    /** Asks the node to stop the server gracefully (the egg's stop command). */
    public function stop(Server $server): void;

    /** Kills the server process (a server hanging in `stopping`, plan 47). */
    public function kill(Server $server): void;
}
