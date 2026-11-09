<?php

namespace DrMxrcy\PlayersPlus\Tests\Fixtures;

use DrMxrcy\PlayersPlus\Services\Query\Channel;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;

/** Hands out a channel per URI; an unknown URI fails like a refused connection. */
final class FakeConnector implements Connector
{
    /** @var list<array{uri: string, timeout: float}> */
    public array $opened = [];

    /** @param  array<string, Channel>  $channels  keyed by URI, e.g. tcp://127.0.0.1:25565 */
    public function __construct(public array $channels = []) {}

    public function open(string $uri, float $timeout): Channel
    {
        $this->opened[] = ['uri' => $uri, 'timeout' => $timeout];

        return $this->channels[$uri] ?? throw new QueryException("connection refused: $uri");
    }
}
