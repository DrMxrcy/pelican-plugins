<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use DrMxrcy\PlayersPlus\Services\LogChunk;
use DrMxrcy\PlayersPlus\Services\LogParser;
use DrMxrcy\PlayersPlus\Services\LogReader;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Plan 28 step 2: incremental, bounded reads of logs/latest.log with rotation detection. */
class LogReaderTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const MODIFIED = '2026-11-09T12:10:45+01:00';

    private Server $server;

    private ?string $log = null;

    private ?int $size = null;

    private string $modified = self::MODIFIED;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createServerModel();
        Http::fake(function (Request $request) {
            $files = $this->log === null ? [] : ['logs/latest.log' => $this->log];

            return FakeDaemonFiles::respond($request, $files, $this->size === null ? [] : ['logs/latest.log' => $this->size], ['logs/latest.log' => $this->modified])
                ?? Http::response('unexpected', 500);
        });
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . "/Fixtures/logs/$name.log");
    }

    private function read(?LogCursor $cursor = null): LogChunk
    {
        return $this->app->make(LogReader::class)->read($this->server, $cursor);
    }

    /** The cursor a scan of $chunk would leave behind. */
    private function cursorAfter(LogChunk $chunk): LogCursor
    {
        return LogCursor::query()->updateOrCreate(['server_id' => $this->server->id], [
            'identity' => $chunk->identity,
            'size' => $chunk->cursorSize(),
            'file_modified_at' => LogCursor::storable($chunk->modified),
            'offset' => $chunk->end,
            'clock_at' => Carbon::parse('2026-11-09 11:10:44'),
        ]);
    }

    private static function reads(): int
    {
        return FakeDaemonFiles::contentReads('latest.log');
    }

    public function test_first_scan_reads_the_whole_log_and_dates_it_from_the_modification_time(): void
    {
        $this->log = $this->fixture('vanilla');

        $chunk = $this->read();

        $this->assertTrue($chunk->restarted);
        $this->assertSame(0, $chunk->start);
        $this->assertSame(strlen($this->log), $chunk->end);
        $this->assertCount(9, $chunk->lines);
        $this->assertSame('2026-11-09', $chunk->firstDate);
        $this->assertSame('+01:00', $chunk->timezone->getName());
        $this->assertSame(hash('sha256', explode("\n", $this->log)[0] . "\n"), $chunk->identity);
    }

    public function test_an_unchanged_log_is_not_downloaded_again(): void
    {
        $this->log = $this->fixture('vanilla');
        $cursor = $this->cursorAfter($this->read());
        $this->assertSame(1, self::reads());

        $chunk = $this->read($cursor->refresh());

        $this->assertSame(1, self::reads(), 'no second download');
        $this->assertSame([], $chunk->lines);
        $this->assertSame($cursor->offset, $chunk->start);
        $this->assertFalse($chunk->restarted);
    }

    public function test_a_grown_log_returns_only_the_new_lines(): void
    {
        $this->log = $this->fixture('vanilla');
        $cursor = $this->cursorAfter($this->read());

        $this->log .= "[12:20:00] [Server thread/INFO]: Bob joined the game\n";
        $this->modified = '2026-11-09T12:20:01+01:00';
        $chunk = $this->read($cursor->refresh());

        $this->assertFalse($chunk->restarted);
        $this->assertSame($cursor->offset, $chunk->start);
        $this->assertSame(["[12:20:00] [Server thread/INFO]: Bob joined the game\n"], $chunk->lines);
        $this->assertNull($chunk->firstDate, 'the cursor clock continues');
    }

    public function test_a_half_written_last_line_waits_for_its_newline(): void
    {
        $this->log = $this->fixture('vanilla') . '[12:20:00] [Server thread/INFO]: Bob joi';

        $chunk = $this->read();

        $this->assertSame(strlen($this->fixture('vanilla')), $chunk->end);
        $this->assertCount(9, $chunk->lines);
    }

    public function test_a_new_first_line_or_a_smaller_file_restarts_at_zero(): void
    {
        $this->log = $this->fixture('vanilla');
        $cursor = $this->cursorAfter($this->read());

        // Rotated and already longer than the old offset.
        $this->log = "[00:00:01] [Server thread/INFO]: Rotated\n" . $this->fixture('vanilla');
        $this->modified = '2026-11-10T00:10:00+01:00';
        $chunk = $this->read($cursor->refresh());
        $this->assertTrue($chunk->restarted);
        $this->assertSame(0, $chunk->start);
        $this->assertCount(10, $chunk->lines);

        // Same first line, but smaller than the offset.
        $cursor = $this->cursorAfter($chunk);
        $this->log = "[00:00:01] [Server thread/INFO]: Rotated\n";
        $this->modified = '2026-11-10T00:20:00+01:00';
        $chunk = $this->read($cursor->refresh());
        $this->assertTrue($chunk->restarted);
        $this->assertSame(0, $chunk->start);
        $this->assertCount(1, $chunk->lines);
    }

    public function test_at_most_one_megabyte_is_parsed_per_scan_and_the_next_scan_continues(): void
    {
        $line = '[12:00:00] [Server thread/INFO]: ' . str_repeat('x', 90) . "\n";
        $this->log = str_repeat($line, 25_000);

        $seen = [];
        $cursor = null;
        for ($scan = 0; $scan < 3; $scan++) {
            $chunk = $this->read($cursor?->refresh());
            $this->assertLessThanOrEqual(LogReader::MAX_CHUNK_BYTES + strlen($line), $chunk->end - $chunk->start);
            array_push($seen, ...$chunk->lines);
            $cursor = $this->cursorAfter($chunk);
            // The file is not touched between scans: the backlog alone must keep the reads going.
        }

        $this->assertSame($this->log, implode('', $seen), 'every byte exactly once');
        $this->assertSame(strlen($this->log), $cursor->offset);
    }

    public function test_an_overlong_line_is_skipped_without_being_buffered_whole(): void
    {
        $this->log = '[12:00:00] [Server thread/INFO]: ' . str_repeat('y', 200_000) . "\n[12:00:01] [Server thread/INFO]: Alice joined the game\n";

        $chunk = $this->read();

        $this->assertSame(strlen($this->log), $chunk->end);
        $this->assertSame(LogParser::MAX_LINE_BYTES + 1, strlen($chunk->lines[0]));
        $this->assertNull(LogParser::line($chunk->lines[0]));
        $this->assertNotNull(LogParser::line($chunk->lines[1])?->event);
    }

    public function test_a_log_that_grew_past_its_listed_size_is_read_only_up_to_that_size(): void
    {
        $listed = $this->fixture('vanilla');
        // The tenant (or the running server) keeps appending after the listing.
        $this->log = $listed . str_repeat("[12:20:00] [Server thread/INFO]: Bob joined the game\n", 50_000);
        $this->size = strlen($listed);

        $chunk = $this->read();

        $this->assertSame(strlen($listed), $chunk->end);
        $this->assertCount(9, $chunk->lines);
    }

    public function test_a_scan_stops_at_its_deadline_and_the_next_one_resumes(): void
    {
        $line = '[12:00:00] [Server thread/INFO]: ' . str_repeat('x', 90) . "\n";
        $this->log = str_repeat($line, 25_000);

        $reader = $this->app->make(LogReader::class);
        $now = 0.0;
        // Every clock reading after the start costs 4 s: the 20 s deadline allows a few blocks.
        $reader->clock = function () use (&$now) {
            $t = $now;
            $now += 4.0;

            return $t;
        };
        $chunk = $reader->read($this->server, null);

        $this->assertGreaterThan(0, $chunk->end);
        $this->assertLessThan(10 * 65536, $chunk->end);
        $this->assertSame(0, $chunk->end % strlen($line), 'stops at a line boundary');
        $this->assertSame($chunk->end, strlen(implode('', $chunk->lines)));
        $this->assertNotNull($chunk->firstDate);

        $this->assertTrue($chunk->more);
        $next = $this->read($this->cursorAfter($chunk)->refresh());
        $this->assertSame($chunk->end, $next->start);
        $this->assertFalse($next->restarted);
        $this->assertGreaterThanOrEqual($chunk->end + LogReader::MAX_CHUNK_BYTES, $next->end, 'the unchanged file is read on, not skipped');
    }

    public function test_an_oversized_log_is_refused_before_download(): void
    {
        $this->log = $this->fixture('vanilla');
        $this->size = LogReader::MAX_LOG_BYTES + 1;

        try {
            $this->read();
            $this->fail('expected a refusal');
        } catch (FileSizeTooLargeException) {
        }
        $this->assertSame(0, self::reads());
    }

    public function test_a_missing_log_is_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->read();
    }
}
