<?php

namespace DrMxrcy\PlayersPlus\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;

/** What one scan read from logs/latest.log: the new complete lines and where they sit. */
final class LogChunk
{
    /**
     * @param  string|null  $identity  sha256 of the first line; null while the log has no complete line
     * @param  bool  $restarted  read from offset 0 because the log is new to the cursor (first scan or rotation)
     * @param  int  $start  byte offset of the first line in $lines
     * @param  int  $end  byte offset just past the last line in $lines (the next scan starts here)
     * @param  list<string>  $lines  complete lines (an overlong one cut to LogParser::MAX_LINE_BYTES + 1)
     * @param  list<int>  $lineEnds  byte offset just past each of $lines
     * @param  string|null  $tail  sha256 of the line ending at $end (as returned by StreamLines); null at offset 0
     * @param  string|null  $firstDate  Y-m-d of the first timestamped line, worked out from the file's
     *                                  modification time; only set when the cursor has no clock to continue
     * @param  DateTimeZone  $timezone  the daemon's zone (from the modification time's UTC offset), which
     *                                  the log's wall-clock times are read in
     * @param  bool  $downloaded  whether the file was streamed (false: unchanged since the last scan)
     * @param  bool  $more  stopped early (chunk cap, deadline, rotation found mid-file): lines are left after $end
     */
    public function __construct(
        public readonly ?string $identity,
        public readonly int $size,
        public readonly ?CarbonImmutable $modified,
        public readonly bool $restarted,
        public readonly int $start,
        public readonly int $end,
        public readonly array $lines,
        public readonly array $lineEnds,
        public readonly ?string $tail,
        public readonly ?string $firstDate,
        public readonly DateTimeZone $timezone,
        public readonly bool $downloaded,
        public readonly bool $more = false,
    ) {}

    /**
     * The size to remember in the cursor. An unchanged size and modification time skip the
     * download next time, so with lines still left it is 0, which forces the next read.
     */
    public function cursorSize(): int
    {
        return $this->more ? 0 : $this->size;
    }
}
