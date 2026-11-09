<?php

namespace DrMxrcy\PlayersPlus\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Reads complete lines from a stream with bounded memory, tracking the byte position. A line
 * longer than $maxLineBytes is still consumed in full but returned cut to $maxLineBytes + 1
 * bytes, so a length check can reject it. A last line without its newline (still being
 * written) is never returned.
 */
final class StreamLines
{
    private const BLOCK = 65536;

    private string $buffer = '';

    private int $position = 0;

    public function __construct(private readonly StreamInterface $stream, private readonly int $maxLineBytes) {}

    /** Byte offset just past the last line returned or skipped. */
    public function position(): int
    {
        return $this->position;
    }

    /** The next complete line including its "\n", or null at the end of the stream. */
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
                // A last line without its newline is still being written: not returned, not counted.
                return null;
            }
        }
    }

    /**
     * Consumes bytes up to $offset without keeping them. False when the stream ends first.
     */
    public function skipTo(int $offset): bool
    {
        while ($this->position + strlen($this->buffer) < $offset) {
            $this->position += strlen($this->buffer);
            $this->buffer = '';
            if (!$this->fill()) {
                return false;
            }
        }
        $cut = $offset - $this->position;
        $this->buffer = substr($this->buffer, $cut);
        $this->position = $offset;

        return true;
    }

    private function fill(): bool
    {
        if ($this->stream->eof()) {
            return false;
        }
        $chunk = $this->stream->read(self::BLOCK);
        $this->buffer .= $chunk;

        return $chunk !== '';
    }
}
