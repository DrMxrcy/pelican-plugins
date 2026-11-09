<?php

namespace DrMxrcy\PlayersPlus\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Dates log lines that carry only a time of day. Each line is placed on the same day as the
 * line before it; a step back in time of more than JITTER_SECONDS is a midnight wrap and
 * moves to the next day, a smaller one is just lines logged slightly out of order.
 *
 * Pure: the starting point comes from the caller (the previous scan's last line, or a
 * first date worked out with firstDate() from the file's modification time).
 */
final class LogClock
{
    /** A step back larger than this is a midnight wrap; anything smaller is out-of-order logging. */
    public const JITTER_SECONDS = 300;

    private ?CarbonImmutable $last;

    /**
     * @param  CarbonInterface|null  $last  the moment of the line before the first one fed in
     * @param  string|null  $firstDate  Y-m-d of the first line when there is no $last
     */
    public function __construct(private readonly DateTimeZone $timezone, ?CarbonInterface $last = null, private readonly ?string $firstDate = null)
    {
        $this->last = $last === null ? null : CarbonImmutable::instance($last)->setTimezone($timezone);
    }

    /** The line's moment, or null while there is nothing to date it against. */
    public function at(LogLine $line): ?CarbonImmutable
    {
        if ($line->date !== null) {
            $at = self::onDay(CarbonImmutable::parse($line->date, $this->timezone), $line->seconds);
        } elseif ($this->last !== null) {
            $at = self::onDay($this->last, $line->seconds);
            if ($at->lessThan($this->last->subSeconds(self::JITTER_SECONDS))) {
                $at = self::onDay($this->last->addDay(), $line->seconds);
            } elseif ($at->greaterThan($this->last->addSeconds(86400 - self::JITTER_SECONDS))) {
                $at = self::onDay($this->last->subDay(), $line->seconds);
            }
        } elseif ($this->firstDate !== null) {
            $at = self::onDay(CarbonImmutable::parse($this->firstDate, $this->timezone), $line->seconds);
        } else {
            return null;
        }

        return $this->last = $at;
    }

    public function last(): ?CarbonImmutable
    {
        return $this->last;
    }

    /**
     * Y-m-d of the first of $lines, given the file was last written at $modified: the last
     * line is the latest moment at its time of day not after $modified, and each midnight
     * wrap between the first and last line moves one day further back. Null without lines.
     *
     * @param  iterable<LogLine>  $lines  every timestamped line from the first one to the end of the file
     */
    public static function firstDate(iterable $lines, CarbonImmutable $modified): ?string
    {
        // Count wraps on a DST-free stand-in calendar.
        $utc = new DateTimeZone('UTC');
        $counter = new self($utc, null, '2000-01-01');
        $end = null;
        foreach ($lines as $line) {
            $end = $counter->at(new LogLine($line->seconds));
        }
        if ($end === null) {
            return null;
        }

        $lastLine = self::onDay($modified, $end->secondsSinceMidnight());
        if ($lastLine->greaterThan($modified->addSeconds(self::JITTER_SECONDS))) {
            $lastLine = $lastLine->subDay();
        }
        $wraps = (int) CarbonImmutable::parse('2000-01-01', $utc)->diffInDays($end->startOfDay());

        return $lastLine->subDays($wraps)->format('Y-m-d');
    }

    private static function onDay(CarbonImmutable $day, int|float $seconds): CarbonImmutable
    {
        $seconds = (int) $seconds;

        return $day->setTime(intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
