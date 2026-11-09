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
 *
 * Log lines are tenant-controlled, so with a $ceiling (now, plus a little) nothing is ever
 * dated after it: a time-of-day line that would land later (a forged chain of backward steps,
 * each read as a midnight wrap) moves back to the latest such moment not after the ceiling,
 * and a line with an explicit date after the ceiling is ignored (null; the clock stays put).
 */
final class LogClock
{
    /** A step back larger than this is a midnight wrap; anything smaller is out-of-order logging. */
    public const JITTER_SECONDS = 300;

    private ?CarbonImmutable $last;

    private readonly ?CarbonImmutable $ceiling;

    /**
     * @param  CarbonInterface|null  $last  the moment of the line before the first one fed in
     * @param  string|null  $firstDate  Y-m-d of the first line when there is no $last
     * @param  CarbonInterface|null  $ceiling  no line is dated after this
     */
    public function __construct(private readonly DateTimeZone $timezone, ?CarbonInterface $last = null, private readonly ?string $firstDate = null, ?CarbonInterface $ceiling = null)
    {
        $this->ceiling = $ceiling === null ? null : CarbonImmutable::instance($ceiling)->setTimezone($timezone);
        $last = $last === null ? null : CarbonImmutable::instance($last)->setTimezone($timezone);
        $this->last = $last !== null && $this->ceiling !== null ? $last->min($this->ceiling) : $last;
    }

    /** The line's moment, or null while there is nothing to date it against. */
    public function at(LogLine $line): ?CarbonImmutable
    {
        if ($line->date !== null) {
            $at = self::onDay(CarbonImmutable::parse($line->date, $this->timezone), $line->seconds);
            if ($this->ceiling !== null && $at->greaterThan($this->ceiling)) {
                return null;
            }
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

        if ($this->ceiling !== null && $at->greaterThan($this->ceiling)) {
            $days = (int) ceil(($at->getTimestamp() - $this->ceiling->getTimestamp()) / 86400);
            $at = self::onDay($at->subDays($days), $line->seconds);
            if ($at->greaterThan($this->ceiling)) {
                $at = self::onDay($at->subDay(), $line->seconds); // a daylight-saving day
            }
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
