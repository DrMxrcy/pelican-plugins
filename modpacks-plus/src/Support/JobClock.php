<?php

namespace DrMxrcy\ModpacksPlus\Support;

/**
 * The running install job's time budget (plan 32 security review). Guzzle applies `timeout`
 * per request, so a job doing several requests (or following redirects) could run far past
 * ~60 s. InstallStep starts the clock for every job; every Modpacks Plus HTTP request then
 * gets at most the time left (minus a reserve for bookkeeping), and a step whose request
 * failed because the time ran out is handed to a fresh job instead of failing the install.
 * Outside a job (page requests) nothing is limited here.
 */
final class JobClock
{
    /** Seconds kept free after the last request for persisting and dispatching. */
    public const RESERVE_SECONDS = 5;

    /** Below this many usable seconds a request is not worth starting. */
    public const MIN_SECONDS = 3;

    private static ?float $deadline = null;

    public static function start(float $seconds): void
    {
        self::$deadline = microtime(true) + $seconds;
    }

    public static function stop(): void
    {
        self::$deadline = null;
    }

    /** Seconds left in the running job, or null outside a job. */
    public static function remaining(): ?float
    {
        return self::$deadline !== null ? self::$deadline - microtime(true) : null;
    }

    /** Whether the running job has too little time left for another request. */
    public static function exhausted(): bool
    {
        $remaining = self::remaining();

        return $remaining !== null && $remaining - self::RESERVE_SECONDS < self::MIN_SECONDS;
    }

    /** A request's timeout: $requested, but never more than the job has left (at least 1 s). */
    public static function timeout(int $requested): int
    {
        $remaining = self::remaining();
        if ($remaining === null) {
            return $requested;
        }

        return max(1, min($requested, (int) floor($remaining - self::RESERVE_SECONDS)));
    }
}
