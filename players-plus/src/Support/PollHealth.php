<?php

namespace DrMxrcy\PlayersPlus\Support;

use Carbon\CarbonImmutable;

/**
 * Whether polling is keeping up. A sample is fresh while it is no older than three poll
 * intervals (plus a minute for the job itself); after that the scheduler or the queue worker is
 * probably not running. Same rule as PlayerList::isStale().
 */
final class PollHealth
{
    /** Oldest polled_at that still counts as fresh at $now. */
    public static function freshFrom(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->utc()->subMinutes(3 * Settings::pollInterval() + 1);
    }
}
