<?php

namespace DrMxrcy\PlayersPlus\Services;

/**
 * One timestamped log line. Most formats carry only the time of day; NeoForge (and newer
 * Forge) also log the date, which then wins over the clock's own date guess.
 */
final class LogLine
{
    /**
     * @param  int  $seconds  seconds since midnight, 0 to 86399
     * @param  string|null  $date  Y-m-d, when the line has one
     */
    public function __construct(
        public readonly int $seconds,
        public readonly ?string $date = null,
        public readonly ?LogEvent $event = null,
    ) {}
}
