<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

/** What Server List Ping returns: the count and a sample of up to ~12 players with UUIDs. */
final class PingResult
{
    /** @param  list<array{name: string, uuid: string|null}>  $players */
    public function __construct(
        public readonly int $online,
        public readonly int $max,
        public readonly array $players,
        public readonly ?string $version,
    ) {}
}
