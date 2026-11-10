<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;

/** ServerPower through the panel's DaemonServerRepository (one Wings call each, short timeouts). */
class DaemonServerPower implements ServerPower
{
    public function state(Server $server): string
    {
        // Uncached on purpose: Server::retrieveStatus() caches for 15 s.
        $details = app(DaemonServerRepository::class)->setServer($server)->getDetails(5);
        $state = $details['state'] ?? null;

        return is_string($state) && $state !== '' ? $state : 'missing';
    }

    public function stop(Server $server): void
    {
        app(DaemonServerRepository::class)->setServer($server)->power('stop')->throw();
    }

    public function kill(Server $server): void
    {
        app(DaemonServerRepository::class)->setServer($server)->power('kill')->throw();
    }
}
