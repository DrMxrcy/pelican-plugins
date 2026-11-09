<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Support\DaemonFiles;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * DaemonFiles::read() trusts neither the listing nor the body: a file that grew (or was
 * swapped) after the listing must never be buffered past the cap.
 */
class DaemonFilesTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createServerModel();
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, int>  $sizes
     */
    private function daemon(array $files, array $sizes = []): void
    {
        Http::fake(fn (Request $request) => FakeDaemonFiles::respond($request, $files, $sizes) ?? Http::response('unexpected', 500));
    }

    public function test_reads_a_file_within_the_cap(): void
    {
        $this->daemon(['ops.json' => '[{"name":"Steve"}]']);

        $this->assertSame('[{"name":"Steve"}]', DaemonFiles::read($this->server, 'ops.json', 1024));
    }

    public function test_a_file_listed_over_the_cap_is_never_downloaded(): void
    {
        $this->daemon(['ops.json' => '[]'], ['ops.json' => 5_000_000]);

        try {
            DaemonFiles::read($this->server, 'ops.json', 1024);
            $this->fail('expected FileSizeTooLargeException');
        } catch (FileSizeTooLargeException) {
        }

        $this->assertSame(0, FakeDaemonFiles::contentReads('ops.json'));
    }

    public function test_a_file_that_grew_after_the_listing_is_refused_not_returned(): void
    {
        // The listing says 10 bytes, the body is 3 MB.
        $this->daemon(['ops.json' => str_repeat('a', 3_000_000)], ['ops.json' => 10]);

        $this->expectException(FileSizeTooLargeException::class);
        DaemonFiles::read($this->server, 'ops.json', 1024);
    }

    public function test_a_body_exactly_at_the_cap_is_returned(): void
    {
        $this->daemon(['ops.json' => str_repeat('b', 1024)], ['ops.json' => 10]);

        $this->assertSame(1024, strlen(DaemonFiles::read($this->server, 'ops.json', 1024)));
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $this->daemon([]);

        $this->expectException(FileNotFoundException::class);
        DaemonFiles::read($this->server, 'ops.json', 1024);
    }
}
