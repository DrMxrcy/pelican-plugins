<?php

namespace DrMxrcy\PlayersPlus\Support;

/**
 * Typed, clamped reads of config('players-plus.*'). Values come from .env, so they can be
 * strings or out of range; everything that schedules or queries goes through here.
 */
final class Settings
{
    public const MIN_INTERVAL = 1;

    public const MAX_INTERVAL = 5;

    public const MAX_RETENTION_DAYS = 3650;

    public static function enabled(): bool
    {
        return filter_var(config('players-plus.enabled', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Kick, ban, pardon, whitelist and op from the panel (plan 29). On by default; a value that
     * is not a recognisable boolean turns it off (fails closed, unlike the read-only settings).
     */
    public static function moderationEnabled(): bool
    {
        return filter_var(config('players-plus.moderation', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /** Minutes between polls. */
    public static function pollInterval(): int
    {
        return self::clampInt(config('players-plus.poll_interval'), self::MIN_INTERVAL, self::MAX_INTERVAL, 2);
    }

    public static function retentionDays(): int
    {
        return self::clampInt(config('players-plus.retention_days'), 1, self::MAX_RETENTION_DAYS, 30);
    }

    /** Days hourly rollups are kept; defaults to a year. */
    public static function rollupRetentionDays(): int
    {
        return self::clampInt(config('players-plus.rollup_retention_days'), 1, self::MAX_RETENTION_DAYS, 365);
    }

    /** Seconds for each network step of a game-server query. */
    public static function timeout(): float
    {
        $value = config('players-plus.timeout');

        return is_numeric($value) ? max(0.5, min(10.0, (float) $value)) : 3.0;
    }

    public static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
