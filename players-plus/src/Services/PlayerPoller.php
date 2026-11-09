<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Services\Query\PingResult;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;
use DrMxrcy\PlayersPlus\Services\Query\QueryResult;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Decides one server's sample. Status rules:
 * - the panel says the server isn't running → offline, no network query;
 * - running, but Ping fails → unreachable (Query is then not tried);
 * - otherwise online, with Query's full name list when enabled, else Ping's sample.
 */
class PlayerPoller
{
    /** Total game-server network time per poll, in multiples of the per-step timeout. */
    public const BUDGET_TIMEOUTS = 3;

    private const MIN_QUERY_TIMEOUT = 0.25;

    public function __construct(
        private readonly MinecraftQuery $query,
        private readonly QueryPortResolver $queryPorts,
    ) {}

    /**
     * A status lookup that returns "missing" after at least this long hit the daemon's 1 s
     * timeout: the node is down or black-holed, not just this server.
     */
    public float $daemonSlowSeconds = 0.9;

    /**
     * @param  int|null  $slot  poll slot; a slow daemon trips a per-node breaker for the rest of it
     */
    public function poll(Server $server, ?int $slot = null): PollResult
    {
        if ($server->isInConflictState()) {
            return PollResult::offline();
        }

        $breaker = $slot === null ? null : "players-plus.node-down.$server->node_id.$slot";
        if ($breaker !== null && Cache::has($breaker)) {
            // Same outcome the panel gives a failing daemon (missing = not running), without waiting on it again.
            return PollResult::offline();
        }

        // Panel-cached (15 s) daemon state; this is not a game-server query. The panel turns
        // every daemon error into ContainerStatus::Missing, so there is nothing to catch here.
        $started = microtime(true);
        $state = $server->retrieveStatus();

        if ($state === ContainerStatus::Missing && $breaker !== null && microtime(true) - $started >= $this->daemonSlowSeconds) {
            Cache::put($breaker, true, Settings::pollInterval() * 60);
        }

        if ($state !== ContainerStatus::Running) {
            return PollResult::offline();
        }

        $address = ServerAddress::forServer($server);
        if ($address === null) {
            return PollResult::unreachable('no allocation');
        }

        $timeout = Settings::timeout();
        $budgetEnds = microtime(true) + self::BUDGET_TIMEOUTS * $timeout;

        // Ping first: it is cheap, it is the only source of UUIDs, and if the game port does
        // not answer, Query is skipped so a dead server costs one timeout, not two.
        try {
            $ping = $this->query->ping($address->host, $address->port, $timeout);
        } catch (QueryException $exception) {
            return PollResult::unreachable('ping: ' . $exception->getMessage());
        }

        $query = null;
        $queryPort = $this->queryPorts->forServer($server, $address->port);
        // Query gets what is left of the job's network budget (its channel may use 2x its timeout).
        $queryTimeout = min($timeout, ($budgetEnds - microtime(true)) / 2);
        if ($queryPort !== null && $queryTimeout >= self::MIN_QUERY_TIMEOUT) {
            try {
                $query = $this->query->query($address->host, $queryPort, $queryTimeout);
            } catch (QueryException) {
                // Ping already answered; the sample just has Ping's partial list.
            }
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

        if ($ping === null) {
            return PollResult::unreachable('no answer');
        }

        return new PollResult(SampleStatus::Online, $ping->online, $ping->max, $ping->players, 'ping');
    }
}
