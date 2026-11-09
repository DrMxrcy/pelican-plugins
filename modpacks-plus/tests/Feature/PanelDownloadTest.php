<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PanelDownload;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Plan 32 step 2: the panel-side streamed download. A host that sends no Content-Length
 * (dist.modpacks.ch) is cut off as soon as it streams past the cap, the partial file is
 * removed and nothing reaches the server; the size and hash are checked before any byte is
 * written; each fetch gets only the time left in its job (so a job stays under ~60 s); a
 * host that fails or times out is retried in a fresh job, a bounded number of times.
 */
class PanelDownloadTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
        Cache::flush();
        Http::swap(new HttpFactory());
        $this->dir = sys_get_temp_dir().'/mp-panel-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function validator(): PlanValidator
    {
        return new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor(FtbPackProvider::ID));
    }

    private function file(string $contents, ?int $size = null): PlanFile
    {
        return new PlanFile('mods/a.jar', [], $size ?? strlen($contents), ['sha1' => sha1($contents)], 'required', 'https://dist.modpacks.ch/modpacks/1/a.jar', PlanFile::VERIFY_PANEL);
    }

    /**
     * A Guzzle handler that behaves like curl for a host without Content-Length: the body
     * arrives in chunks written to the sink, and the progress callback runs after each one
     * (an exception from it stops the transfer).
     *
     * @param  array<int, string>  $chunks
     */
    private function streamingHandler(array $chunks, int &$sent): \Closure
    {
        return function (RequestInterface $request, array $options) use ($chunks, &$sent) {
            $response = new Response(200, []);
            ($options['on_headers'] ?? fn () => null)($response);

            $sink = fopen((string) $options['sink'], 'w');
            $downloaded = 0;
            try {
                foreach ($chunks as $chunk) {
                    fwrite($sink, $chunk);
                    $downloaded += strlen($chunk);
                    $sent = $downloaded;
                    ($options['progress'] ?? fn () => null)(0, $downloaded, 0, 0);
                }
            } catch (\Throwable $exception) {
                fclose($sink);

                return Create::rejectionFor($exception);
            }
            fclose($sink);

            return Create::promiseFor($response);
        };
    }

    public function test_a_body_without_content_length_is_cut_off_at_the_cap_and_removed(): void
    {
        $sent = 0;
        $this->app->instance(ModrinthClient::class, new ModrinthClient($this->streamingHandler(array_fill(0, 100, str_repeat('x', 10)), $sent)));
        $temp = $this->dir.'/files/abc';

        try {
            // Declared (and capped at) 25 bytes; the host streams 1000.
            PanelDownload::fetch($this->file(str_repeat('x', 25)), $temp, $this->validator(), new Caps(), 30);
            $this->fail('An over-cap body was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('panel_fetch_too_large', $exception->reason);
        }

        // Stopped at the first chunk past the cap, not after the whole body.
        $this->assertSame(30, $sent);
        $this->assertFileDoesNotExist($temp);
        $this->assertSame([], File::files($this->dir.'/files'));
    }

    public function test_a_file_over_the_panel_fetch_cap_is_never_requested(): void
    {
        $sent = 0;
        $this->app->instance(ModrinthClient::class, new ModrinthClient($this->streamingHandler(['x'], $sent)));

        try {
            PanelDownload::fetch($this->file('', 33 * 1024 * 1024), $this->dir.'/files/big', $this->validator(), new Caps(), 30);
            $this->fail('An over-cap file was fetched');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('panel_fetch_too_large', $exception->reason);
        }

        $this->assertSame(0, $sent);
    }

    public function test_the_right_bytes_are_returned_and_the_temp_file_removed(): void
    {
        $sent = 0;
        $this->app->instance(ModrinthClient::class, new ModrinthClient($this->streamingHandler(['ab', 'cd'], $sent)));
        $temp = $this->dir.'/files/ok';

        $this->assertSame('abcd', PanelDownload::fetch($this->file('abcd'), $temp, $this->validator(), new Caps(), 30));
        $this->assertFileDoesNotExist($temp);
    }

    public function test_wrong_bytes_or_a_short_body_are_refused_and_removed(): void
    {
        foreach ([['abcX', 'hash_mismatch'], ['abc', 'download_incomplete']] as [$body, $reason]) {
            $sent = 0;
            $this->app->instance(ModrinthClient::class, new ModrinthClient($this->streamingHandler([$body], $sent)));
            $temp = $this->dir.'/files/'.$reason;

            try {
                PanelDownload::fetch($this->file('abcd'), $temp, $this->validator(), new Caps(), 30);
                $this->fail("$body was accepted");
            } catch (UnsafePlanException|StepFailedException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
            $this->assertFileDoesNotExist($temp);
        }
    }

    public function test_each_fetch_only_gets_the_time_left_in_its_job(): void
    {
        $this->assertSame(ModrinthClient::DOWNLOAD_TIMEOUT, PanelDownload::timeout(0.0));
        $this->assertSame(10, PanelDownload::timeout((float) InstallStep::BUDGET_SECONDS));
        $this->assertNull(PanelDownload::timeout(31.0));

        // The longest job: a fetch started right at the end of the budget, then the write.
        $this->assertLessThanOrEqual(60, InstallStep::BUDGET_SECONDS + (int) PanelDownload::timeout((float) InstallStep::BUDGET_SECONDS) + PanelDownload::WRITE_RESERVE_SECONDS);
        $this->assertLessThanOrEqual(60, PanelDownload::JOB_SECONDS);
    }

    /** An FTB pack of three dist.modpacks.ch files on a Forge server. */
    private function ftbServer(): Server
    {
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n", 'world/level.dat' => 'level']);

        return $server;
    }

    /**
     * @param  array<string, mixed>  $downloads  URL => fake response (or closure)
     */
    private function fakeFtb(array $downloads = []): void
    {
        $files = [
            Ftb::file('./mods', 'a.jar', 'a-bytes', 'mod'),
            Ftb::file('./mods', 'b.jar', 'b-bytes', 'mod'),
            Ftb::file('./config', 'c.toml', 'c=1'),
        ];
        $stubs = [
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => Http::response(Ftb::version($files)),
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
        ];
        foreach ([['./mods/a.jar', 'a-bytes'], ['./mods/b.jar', 'b-bytes'], ['./config/c.toml', 'c=1']] as [$path, $contents]) {
            $url = Ftb::distUrl($path, $contents);
            $stubs[$url] = $downloads[$url] ?? Http::response($contents);
        }

        Http::fake($stubs + ['*' => Http::response('unexpected', 500)]);
    }

    private function install(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, 'FTB Test Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));

        return $this->latestInstall();
    }

    public function test_an_over_cap_download_in_an_install_leaves_nothing_behind(): void
    {
        $server = $this->ftbServer();
        $url = Ftb::distUrl('./mods/b.jar', 'b-bytes');
        $this->fakeFtb([$url => Http::response('b-bytes'.str_repeat('!', 4096))]);

        $install = $this->install($server);

        $this->assertSame('panel_fetch_too_large', $install->error);
        $this->assertArrayNotHasKey('mods/b.jar', $this->files->files);
        foreach (array_keys($this->files->files) as $path) {
            $this->assertStringNotContainsString('b.jar', $path);
        }
        $this->assertSame([], array_values(array_filter($this->files->callsOf('write'), fn (string $path) => str_contains($path, 'b.jar'))));
        $this->assertSame([], $this->files->callsOf('pull'));
        // No partial file on the panel either.
        $this->assertDirectoryDoesNotExist(app(InstallEngine::class)->workDir($install).'/files');
    }

    public function test_a_failing_host_is_retried_in_a_fresh_job_a_bounded_number_of_times(): void
    {
        $server = $this->ftbServer();
        $url = Ftb::distUrl('./mods/b.jar', 'b-bytes');
        $calls = 0;
        $this->fakeFtb([$url => function () use (&$calls) {
            return ++$calls === 1 ? Http::response('busy', 503) : Http::response('b-bytes');
        }]);

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('b-bytes', $this->files->files['mods/b.jar'] ?? null);
        $this->assertSame(2, $calls);
        $this->assertContains('fetch_retry', array_column($install->log, 'key'));
    }

    public function test_a_host_that_keeps_failing_fails_the_install(): void
    {
        $server = $this->ftbServer();
        $this->fakeFtb([Ftb::distUrl('./mods/b.jar', 'b-bytes') => Http::response('busy', 503)]);

        $install = $this->install($server);

        $this->assertSame('download_failed', $install->error);
        $this->assertArrayNotHasKey('mods/b.jar', $this->files->files);
    }

    public function test_downloads_run_in_batches(): void
    {
        config()->set('modpacks-plus.download_batch', 1);
        $server = $this->ftbServer();
        $this->fakeFtb();

        $jobs = 0;
        app(InstallEngine::class)->start($server, null, FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, 'FTB Test Pack', '1.0.0');
        $this->runJobs(between: function (InstallStep $job) use ($server, &$jobs) {
            $this->finishBackups($server);
            $jobs += $job instanceof DownloadStep ? 1 : 0;
        });

        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status);
        $this->assertGreaterThanOrEqual(3, $jobs);
    }

    public function test_the_fetch_timeout_given_to_the_host_never_exceeds_the_job_budget(): void
    {
        $timeouts = new \ArrayObject();
        $this->app->instance(ModrinthClient::class, new class($timeouts) extends ModrinthClient
        {
            /** @param  \ArrayObject<int, int>  $timeouts */
            public function __construct(private readonly \ArrayObject $timeouts)
            {
                parent::__construct();
            }

            public function download(string $url, string $destination, int $maxBytes, PlanValidator $validator, int $timeout = self::DOWNLOAD_TIMEOUT, string $tooLargeReason = 'archive_too_large'): void
            {
                $this->timeouts[] = $timeout;
                parent::download($url, $destination, $maxBytes, $validator, $timeout, $tooLargeReason);
            }
        });
        $server = $this->ftbServer();
        $this->fakeFtb();

        $install = $this->install($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertCount(3, $timeouts);
        foreach ($timeouts as $timeout) {
            $this->assertGreaterThanOrEqual(PanelDownload::MIN_FETCH_SECONDS, $timeout);
            $this->assertLessThanOrEqual(ModrinthClient::DOWNLOAD_TIMEOUT, $timeout);
        }
    }

    public function test_a_transient_host_failure_is_a_pack_unavailable_exception(): void
    {
        $this->app->instance(ModrinthClient::class, new ModrinthClient(fn () => Create::promiseFor(new Response(503, [], 'busy'))));

        $this->expectException(PackUnavailableException::class);
        PanelDownload::fetch($this->file('abcd'), $this->dir.'/files/x', $this->validator(), new Caps(), 30);
    }
}
