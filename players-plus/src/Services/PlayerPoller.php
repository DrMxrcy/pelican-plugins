<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Services\Query\PingResult;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;
use DrMxrcy\PlayersPlus\Services\Query\QueryResult;
use DrMxrcy\PlayersPlus\Support\Settings;
use Throwable;

/**
 * Decides one server's sample. Status rules:
 * - the panel says the server isn't running → offline, no network query;
 * - running, but Query and Ping both fail → unreachable;
 * - otherwise online, with Query's full name list when enabled, else Ping's sample.
 */
class PlayerPoller
{
    public function __construct(
        private readonly MinecraftQuery $query,
        private readonly QueryPortResolver $queryPorts,
    ) {}

    public function poll(Server $server): PollResult
    {
        if ($server->isInConflictState()) {
            return PollResult::offline();
        }

        try {
            // Panel-cached (15 s) daemon state; this is not a game-server query.
            $state = $server->retrieveStatus();
        } catch (Throwable $exception) {
            return PollResult::unreachable('daemon: ' . $exception->getMessage());
        }

        if ($state !== ContainerStatus::Running) {
            return PollResult::offline();
        }

        $address = ServerAddress::forServer($server);
        if ($address === null) {
            return PollResult::unreachable('no allocation');
        }

        $timeout = Settings::timeout();
        $errors = [];

        $query = null;
        $queryPort = $this->queryPorts->forServer($server, $address->port);
        if ($queryPort !== null) {
            try {
                $query = $this->query->query($address->host, $queryPort, $timeout);
            } catch (QueryException $exception) {
                $errors[] = 'query: ' . $exception->getMessage();
            }
        }

        // Ping even when Query worked: it is the only source of UUIDs.
        $ping = null;
        try {
            $ping = $this->query->ping($address->host, $address->port, $timeout);
        } catch (QueryException $exception) {
            $errors[] = 'ping: ' . $exception->getMessage();
        }

        if ($query === null && $ping === null) {
            return PollResult::unreachable(implode('; ', $errors));
        }

        return self::merge($query, $ping);
    }

    public static function merge(?QueryResult $query, ?PingResult $ping): PollResult
    {
        if ($query !== null) {
            $uuids = [];
            foreach ($ping->players ?? [] as $player) {
                $uuids[$player['name']] = $player['uuid'];
            }

            return new PollResult(
                SampleStatus::Online,
                $query->online,
                $query->max,
                array_map(fn (string $name) => ['name' => $name, 'uuid' => $uuids[$name] ?? null], $query->names),
                'query',
            );
        }

        assert($ping !== null);

        return new PollResult(SampleStatus::Online, $ping->online, $ping->max, $ping->players, 'ping');
    }
}
