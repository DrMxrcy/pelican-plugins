<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

interface Connector
{
    /**
     * @param  string  $uri  tcp://host:port or udp://host:port, IPv6 bracketed
     *
     * @throws QueryException
     */
    public function open(string $uri, float $timeout): Channel;
}
