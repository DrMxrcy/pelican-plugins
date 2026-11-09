<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

/** What Query (full stat) returns: the count and every online name, without UUIDs. */
final class QueryResult
{
    /** @param  list<string>  $names */
    public function __construct(
        public readonly int $online,
        public readonly int $max,
        public readonly array $names,
        public readonly ?string $version,
    ) {}
}
