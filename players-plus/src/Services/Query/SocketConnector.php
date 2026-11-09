<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

final class SocketConnector implements Connector
{
    public function open(string $uri, float $timeout): Channel
    {
        return SocketChannel::open($uri, $timeout);
    }
}
