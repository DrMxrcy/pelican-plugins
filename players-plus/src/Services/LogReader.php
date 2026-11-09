<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use DrMxrcy\PlayersPlus\Support\DaemonFiles;
use DrMxrcy\PlayersPlus\Support\StreamLines;
use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Throwable;

/**
 * Reads the next part of a server's logs/latest.log after its cursor.
 *
 * - Unchanged size and modification time: nothing is downloaded (one directory listing).
 * - Otherwise the file is streamed (Wings has no range requests, so from byte 0): the bytes
 *   before the cursor are skipped without being kept, and at most MAX_CHUNK_BYTES of complete
 *   lines after it are returned. Memory stays bounded whatever the file size.
 * - Rotation: a different first line, or a file smaller than the offset, restarts at 0.
 * - Files over MAX_LOG_BYTES are refused before any download, and no read ever takes more
 *   than min(listed size, MAX_LOG_BYTES) bytes from the stream, even if the file grew since.
 * - Every scan stops at a wall-clock deadline (DEADLINE_SECONDS) and keeps the complete lines
 *   it has; the next scan resumes from the cursor.
 */
class LogReader
{
    public const PATH = 'logs/latest.log';

    /** Most new bytes parsed per scan; a longer backlog continues on the next scan. */
    public const MAX_CHUNK_BYTES = 1_048_576;

    /** Larger logs are not scanned at all (each grown scan streams up to the cursor). */
    public const MAX_LOG_BYTES = 33_554_432;

    /** Seconds a scan may spend reading, well under the job's timeout. */
    public const DEADLINE_SECONDS = 20;

    /** @var Closure(): float seconds, monotonic; replaceable in tests */
    public Closure $clock;

    public function __construct()
    {
        $this->clock = fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @throws FileNotFoundException
     * @throws FileSizeTooLargeException
     * @throws Throwable any other daemon error
     */
    public function read(Server $server, ?LogCursor $cursor): LogChunk
    {
        $stat = DaemonFiles::stat($server, self::PATH);
        if ($stat['size'] > self::MAX_LOG_BYTES) {
            throw new FileSizeTooLargeException();
        }
        $modified = $stat['modified'];
        $timezone = $modified?->getTimezone() ?? new DateTimeZone((string) config('app.timezone', 'UTC'));

        if ($cursor?->identity !== null && $stat['size'] === $cursor->size && $modified !== null
            && $cursor->file_modified_at?->getTimestamp() === $modified->getTimestamp()) {
            return new LogChunk($cursor->identity, $stat['size'], $modified, false, $cursor->offset, $cursor->offset, [], null, $timezone, false);
        }

        $deadline = ($this->clock)() + self::DEADLINE_SECONDS;
        $stream = DaemonFiles::open($server, self::PATH, self::DEADLINE_SECONDS + 5);
        try {
            $reader = new StreamLines($stream, LogParser::MAX_LINE_BYTES, min($stat['size'], self::MAX_LOG_BYTES), fn (): bool => ($this->clock)() >= $deadline);
            $first = $reader->next();
            if ($first === null) {
                return new LogChunk(null, $stat['size'], $modified, true, 0, 0, [], null, $timezone, true);
            }

            $identity = hash('sha256', $first);
            $restarted = $cursor === null || $cursor->identity !== $identity || $stat['size'] < $cursor->offset;
            $start = $restarted ? 0 : $cursor->offset;

            $lines = [];
            if ($start === 0) {
                $lines[] = $first;
            } elseif (!$reader->skipTo($start)) {
                // Out of time, or the file shrank since the listing: the next scan tries again.
                return new LogChunk($identity, $stat['size'], $modified, false, $start, $start, [], null, $timezone, true, true);
            }

            while ($reader->position() - $start < self::MAX_CHUNK_BYTES && ($line = $reader->next()) !== null) {
                $lines[] = $line;
            }
            $end = $reader->position();
            $more = $reader->timedOut() || $end - $start >= self::MAX_CHUNK_BYTES;

            $firstDate = null;
            if ($restarted || $cursor->clock_at === null) {
                // Dating needs every line to the end of the file: the last one is at most the
                // modification time, and each midnight wrap before it is a day back. Cut short by
                // the deadline, the last line read stands in for the end (unread wraps are lost).
                $firstDate = LogClock::firstDate(self::timed($lines, $reader), $modified ?? CarbonImmutable::now($timezone));
            }

            return new LogChunk($identity, $stat['size'], $modified, $restarted, $start, $end, $lines, $firstDate, $timezone, true, $more);
        } finally {
            $stream->close();
        }
    }

    /**
     * @param  list<string>  $lines
     * @return Generator<int, LogLine>
     */
    private static function timed(array $lines, StreamLines $rest): Generator
    {
        foreach ($lines as $raw) {
            if (($line = LogParser::line($raw)) !== null) {
                yield $line;
            }
        }
        while (($raw = $rest->next()) !== null) {
            if (($line = LogParser::line($raw)) !== null) {
                yield $line;
            }
        }
    }
}
