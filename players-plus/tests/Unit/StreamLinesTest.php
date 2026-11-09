<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Support\StreamLines;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/** The log stream is tenant-controlled: bytes, line length and time are all bounded. */
class StreamLinesTest extends TestCase
{
    /** An endless (or $total-byte) stream of $byte, generated on demand so the test holds none of it. */
    private static function pump(string $byte, ?int $total = null, int &$served = 0): PumpStream
    {
        $served = 0;

        return new PumpStream(function (int $length) use ($byte, $total, &$served) {
            $length = $total === null ? $length : min($length, $total - $served);
            if ($length <= 0) {
                return false;
            }
            $served += $length;

            return str_repeat($byte, $length);
        });
    }

    public function test_complete_lines_and_positions(): void
    {
        $lines = new StreamLines(Utils::streamFor("a\nbb\nccc"), 100, 1000);

        $this->assertSame("a\n", $lines->next());
        $this->assertSame("bb\n", $lines->next());
        $this->assertNull($lines->next(), 'a last line without newline is not returned');
        $this->assertSame(5, $lines->position());
    }

    public function test_never_reads_past_the_byte_limit_however_much_the_sender_has(): void
    {
        $served = 0;
        $lines = new StreamLines(self::pump("\n", null, $served), 100, 200_000);

        $count = 0;
        while ($lines->next() !== null) {
            $count++;
        }

        $this->assertSame(200_000, $count);
        $this->assertSame(200_000, $lines->bytesRead());
        $this->assertLessThanOrEqual(200_000 + StreamLines::BLOCK, $served);
    }

    public function test_a_line_without_a_newline_does_not_grow_the_buffer(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $lines = new StreamLines(self::pump('x', 64 * 1024 * 1024), 4096, 64 * 1024 * 1024);
        $this->assertNull($lines->next());

        $this->assertSame(64 * 1024 * 1024, $lines->bytesRead(), 'consumed in full');
        $this->assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $before, 'held at most a block plus the cap');
    }

    public function test_an_overlong_line_is_cut_but_the_next_line_is_intact(): void
    {
        $lines = new StreamLines(Utils::streamFor(str_repeat('y', 300_000) . "\nok\n"), 4096, 1_000_000);

        $this->assertSame(4097, strlen((string) $lines->next()));
        $this->assertSame("ok\n", $lines->next());
        $this->assertSame(300_004, $lines->position());
    }

    public function test_the_deadline_stops_reading_and_keeps_the_position(): void
    {
        $fills = 0;
        $lines = new StreamLines(self::pump("\n"), 100, PHP_INT_MAX, function () use (&$fills) {
            return ++$fills > 3;
        });

        $count = 0;
        while ($lines->next() !== null) {
            $count++;
        }

        $this->assertTrue($lines->timedOut());
        $this->assertSame(3 * StreamLines::BLOCK, $count);
        $this->assertSame($count, $lines->position());
    }

    public function test_skip_to_respects_the_deadline(): void
    {
        $lines = new StreamLines(self::pump("\n"), 100, PHP_INT_MAX, fn () => true);

        $this->assertFalse($lines->skipTo(10));
        $this->assertTrue($lines->timedOut());
        $this->assertSame(0, $lines->position());
    }
}
