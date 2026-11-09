<?php

namespace DrMxrcy\PlayersPlus\Support;

use Closure;
use Psr\Http\Message\StreamInterface;

/**
 * Reads complete lines from a tenant-controlled stream with bounded memory, bounded bytes and
 * a wall-clock deadline, tracking the byte position.
 *
 * - At most $limit bytes are ever read from the stream; after that it counts as ended, however
 *   much more the sender has (the file may have grown since its size was checked).
 * - A line longer than $maxLineBytes is still consumed in full but kept (and returned) cut to
 *   $maxLineBytes + 1 bytes, so the buffer never holds more than that plus one read block.
 * - Once $expired returns true no more is read; timedOut() then says so, and the caller keeps
 *   what it has and resumes from position() next time.
 * - A last line without its newline (still being written, or cut by a limit) is never returned.
 */
final class StreamLines
{
    public const BLOCK = 65536;

    private string $buffer = '';

    private int $position = 0;

    private int $read = 0;

    private bool $timedOut = false;

    /** @param  (Closure(): bool)|null  $expired */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $maxLineBytes,
        private readonly int $limit,
        private readonly ?Closure $expired = null,
    ) {}

    /** Byte offset just past the last line returned or skipped. */
    public function position(): int
    {
        return $this->position;
    }

    /** Bytes taken from the stream so far (never more than the limit). */
    public function bytesRead(): int
    {
        return $this->read;
    }

    /** Whether reading stopped at the deadline rather than at the end or the limit. */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    /** The next complete line including its "\n", or null when no complete line is left. */
    public function next(): ?string
    {
        $cap = $this->maxLineBytes + 1;
        $line = '';
        $consumed = 0;
        while (true) {
            $newline = strpos($this->buffer, "\n");
            if ($newline !== false) {
                $piece = substr($this->buffer, 0, $newline + 1);
                $this->buffer = substr($this->buffer, $newline + 1);
                $this->position += $consumed + strlen($piece);
                $line .= $piece;

                return strlen($line) > $cap ? substr($line, 0, $cap) : $line;
            }

            // An overlong line: keep only what the cap needs, but remember every byte it used.
            if (strlen($line) + strlen($this->buffer) > $cap) {
                $line .= substr($this->buffer, 0, max(0, $cap - strlen($line)));
                $consumed += strlen($this->buffer);
                $this->buffer = '';
            }

            if (!$this->fill()) {
                // A last line without its newline: not returned, not counted.
                return null;
            }
        }
    }

    /** Consumes bytes up to $offset without keeping them. False when the stream stops first. */
    public function skipTo(int $offset): bool
    {
        while ($this->position + strlen($this->buffer) < $offset) {
            $this->position += strlen($this->buffer);
            $this->buffer = '';
            if (!$this->fill()) {
                return false;
            }
        }
        $this->buffer = substr($this->buffer, $offset - $this->position);
        $this->position = $offset;

        return true;
    }

    private function fill(): bool
    {
        if ($this->read >= $this->limit || $this->timedOut) {
            return false;
        }
        if ($this->expired !== null && ($this->expired)()) {
            $this->timedOut = true;

            return false;
        }
        if ($this->stream->eof()) {
            return false;
        }

        $chunk = $this->stream->read(min(self::BLOCK, $this->limit - $this->read));
        $this->read += strlen($chunk);
        $this->buffer .= $chunk;

        return $chunk !== '';
    }
}
