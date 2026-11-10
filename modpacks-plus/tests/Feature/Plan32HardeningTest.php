<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use DrMxrcy\ModpacksPlus\Support\JsonApiClient;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\Ftb;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Plan 32 security review: job time is hard-bounded (one deadline across redirect hops, at
 * most one hop, every request capped by the job's remaining time, a step that ran out of
 * time continues in a fresh job), and redirects are refused for real (Guzzle's redirect
 * middleware runs, no Http::fake above it).
 */
class Plan32HardeningTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new HttpFactory());
        Cache::flush();
        $this->destination = sys_get_temp_dir().'/mp-hard-'.bin2hex(random_bytes(4)).'/file';
    }

    protected function tearDown(): void
    {
        JobClock::stop();
        @unlink($this->destination);
        @rmdir(dirname($this->destination));

        parent::tearDown();
    }

    private static function validator(string $provider): PlanValidator
    {
        return new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor($provider));
    }

    public function test_the_json_client_never_follows_a_redirect(): void
    {
        // Under the real redirect middleware: with allow_redirects on, the second answer would be used.
        $handler = new MockHandler([
            new Response(302, ['Location' => FtbPackProvider::API_URL.'/modpack/101']),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":101}'),
        ]);
        $client = new JsonApiClient(FtbPackProvider::API_URL, 'ftb_unavailable', 'ftb_not_found', handler: $handler);

        try {
            $client->get(['modpack', '100']);
            $this->fail('A redirect was followed');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('ftb_unavailable', $exception->reason);
        }

        $this->assertCount(1, $handler);
    }

    public function test_the_json_client_never_waits_longer_than_the_job_has_left(): void
    {
        $timeouts = [];
        $client = new JsonApiClient(FtbPackProvider::API_URL, 'ftb_unavailable', 'ftb_not_found', handler: function (RequestInterface $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        });

        $client->get(['modpack', '100'], [], 30);
        JobClock::start(15);
        $client->get(['modpack', '100'], [], 30);

        $this->assertSame(30, $timeouts[0]);
        $this->assertLessThanOrEqual(10, $timeouts[1]);
    }

    /** @return array<string, array{string, string}> */
    public static function ownHosts(): array
    {
        return [
            'ftb' => [FtbPackProvider::ID, 'https://dist.modpacks.ch/modpacks/1/a.jar'],
            'atlauncher' => [AtLauncherPackProvider::ID, 'https://download.nodecdn.net/containers/atl/packs/P/files/a.jar'],
            'atlauncher partner container' => [AtLauncherPackProvider::ID, 'https://download.nodecdn.net/containers/reforged/server/release/9.4.2/a.jar'],
        ];
    }

    #[DataProvider('ownHosts')]
    public function test_a_redirect_off_the_allowlist_from_a_source_host_is_never_followed(string $provider, string $url): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'secret'),
        ]);

        try {
            (new ModrinthClient($handler))->download($url, $this->destination, 100, self::validator($provider));
            $this->fail('A redirect off the allowlist was followed');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('no_allowed_url', $exception->reason);
        }

        $this->assertCount(1, $handler);
        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_a_download_follows_at_most_one_redirect(): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://mediafilez.forgecdn.net/files/1/2/a.jar']),
            new Response(302, ['Location' => 'https://edge.forgecdn.net/files/1/3/a.jar']),
            new Response(200, [], 'jar'),
        ]);

        $this->expectException(PackUnavailableException::class);
        try {
            (new ModrinthClient($handler))->download('https://edge.forgecdn.net/files/1/2/a.jar', $this->destination, 100, self::validator('curseforge'));
        } finally {
            $this->assertCount(1, $handler);
            $this->assertFileDoesNotExist($this->destination);
        }
    }

    /** @return array<string, array{string}> */
    public static function md5Urls(): array
    {
        return [
            'atl container' => ['https://download.nodecdn.net/containers/atl/packs/P/files/a.jar'],
            // Plan 32 step 6: a `download: direct` file in a partner container (Pixelmon's server jar).
            'partner container' => ['https://download.nodecdn.net/containers/reforged/server/release/9.4.2/a.jar'],
        ];
    }

    #[DataProvider('md5Urls')]
    public function test_an_md5_download_follows_no_redirect_at_all(string $url): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://download.nodecdn.net/containers/atl/packs/P/files/b.jar']),
            new Response(200, [], 'jar'),
        ]);

        $this->expectException(PackUnavailableException::class);
        try {
            (new ModrinthClient($handler))->download($url, $this->destination, 100, self::validator('atlauncher'), maxRedirects: 0);
        } finally {
            $this->assertCount(1, $handler);
        }
    }

    public function test_the_size_lookup_is_one_head_request_that_never_follows_a_redirect(): void
    {
        $url = 'https://download.nodecdn.net/containers/reforged/server/release/9.4.2/a.jar';
        $requests = [];
        $answers = [
            new Response(302, ['Location' => 'https://download.nodecdn.net/containers/atl/packs/P/files/b.jar']),
            new Response(200, ['Content-Length' => '3']),
        ];
        $handler = function (RequestInterface $request, array $options) use (&$requests, &$answers) {
            $requests[] = [$request->getMethod(), (string) $request->getUri(), $request->getHeaderLine('User-Agent')];

            return Create::promiseFor(array_shift($answers));
        };

        $this->assertNull((new ModrinthClient($handler))->contentLength($url, self::validator('atlauncher')));
        $this->assertCount(1, $requests);
        $this->assertSame(['HEAD', $url], array_slice($requests[0], 0, 2));
        $this->assertStringStartsWith('DrMxrcy/modpacks-plus/', $requests[0][2]);
    }

    /** @return array<string, array{Response, ?int}> */
    public static function sizeAnswers(): array
    {
        return [
            'size' => [new Response(200, ['Content-Length' => '43258781']), 43258781],
            'no size' => [new Response(200), null],
            'not a number' => [new Response(200, ['Content-Length' => '12abc']), null],
            'negative' => [new Response(200, ['Content-Length' => '-1']), null],
            'absurd' => [new Response(200, ['Content-Length' => '99999999999999999999']), null],
            'compressed' => [new Response(200, ['Content-Length' => '3', 'Content-Encoding' => 'gzip']), null],
            'not found' => [new Response(404, ['Content-Length' => '3']), null],
        ];
    }

    #[DataProvider('sizeAnswers')]
    public function test_the_size_lookup_only_trusts_a_plain_content_length_on_a_200(Response $answer, ?int $expected): void
    {
        $handler = new MockHandler([$answer]);

        $this->assertSame($expected, (new ModrinthClient($handler))->contentLength('https://download.nodecdn.net/containers/reforged/a.jar', self::validator('atlauncher')));
    }

    public function test_a_failing_size_lookup_is_worth_another_attempt(): void
    {
        $handler = new MockHandler([new Response(503)]);

        $this->expectException(PackUnavailableException::class);
        (new ModrinthClient($handler))->contentLength('https://download.nodecdn.net/containers/reforged/a.jar', self::validator('atlauncher'));
    }

    public function test_the_size_lookup_refuses_a_url_off_the_allowlist_without_a_request(): void
    {
        $handler = new MockHandler([new Response(200, ['Content-Length' => '3'])]);

        try {
            (new ModrinthClient($handler))->contentLength('https://download.nodecdn.net.evil.com/containers/reforged/a.jar', self::validator('atlauncher'));
            $this->fail('An off-allowlist URL was looked up');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('no_allowed_url', $exception->reason);
        }
        $this->assertCount(1, $handler);
    }

    public function test_a_slow_redirect_chain_shares_one_deadline(): void
    {
        $requests = 0;
        $handler = function (RequestInterface $request, array $options) use (&$requests) {
            $requests++;
            // Each hop alone is within the per-request timeout; together they are not.
            usleep(1_200_000);

            return Create::promiseFor($requests === 1
                ? new Response(302, ['Location' => 'https://mediafilez.forgecdn.net/files/1/2/a.jar'])
                : new Response(200, [], 'jar'));
        };

        $started = microtime(true);
        try {
            (new ModrinthClient($handler))->download('https://edge.forgecdn.net/files/1/2/a.jar', $this->destination, 100, self::validator('curseforge'), 1);
            $this->fail('A chain past its deadline was finished');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('download_failed', $exception->reason);
        }

        $this->assertSame(1, $requests);
        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_a_download_inside_a_job_never_gets_more_than_the_job_has_left(): void
    {
        $timeouts = [];
        $handler = function (RequestInterface $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;
            fwrite($options['sink'], 'jar');

            return Create::promiseFor(new Response(200, [], 'jar'));
        };

        JobClock::start(12);
        (new ModrinthClient($handler))->download('https://edge.forgecdn.net/files/1/2/a.jar', $this->destination, 100, self::validator('curseforge'), 45);

        $this->assertLessThanOrEqual(7, $timeouts[0]);
    }

    public function test_a_step_whose_request_ran_out_of_time_continues_in_a_fresh_job(): void
    {
        $this->setUpScenario();
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n"]);

        $calls = 0;
        Http::swap(new HttpFactory());
        Http::fake([
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => function () use (&$calls) {
                // The first answer comes too late: the job's time is used up and the request fails.
                if (++$calls === 1) {
                    JobClock::start(1);

                    return Http::response('timeout', 504);
                }

                return Http::response(Ftb::version([Ftb::file('./config', 'a.toml', 'a=1')]));
            },
            Ftb::API.'modpack/'.Ftb::PACK_ID => Http::response(Ftb::pack()),
            Ftb::distUrl('./config/a.toml', 'a=1') => Http::response('a=1'),
            '*' => Http::response('unexpected', 500),
        ]);

        app(InstallEngine::class)->start($server, null, FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, 'FTB Test Pack', '1.0.0');
        $this->runJobs(between: fn () => $this->finishBackups($server));

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(2, $calls);
        $this->assertContains('time_retry', array_column($install->log, 'key'));
        $this->assertSame('a=1', $this->files->files['config/a.toml'] ?? null);
    }

    public function test_a_step_that_keeps_running_out_of_time_fails(): void
    {
        $this->setUpScenario();
        [, $server] = $this->scenarioServer([], ['minecraft', 'forge']);
        $this->files->seed(['server.properties' => "level-name=world\n"]);

        Http::swap(new HttpFactory());
        Http::fake([
            Ftb::API.'modpack/'.Ftb::PACK_ID.'/'.Ftb::VERSION_ID => function () {
                JobClock::start(1);

                return Http::response('timeout', 504);
            },
            '*' => Http::response(Ftb::pack()),
        ]);

        app(InstallEngine::class)->start($server, null, FtbPackProvider::ID, (string) Ftb::PACK_ID, (string) Ftb::VERSION_ID, 'FTB Test Pack', '1.0.0');
        $this->runJobs();

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('ftb_unavailable', $install->error);
        $this->assertCount(5, array_filter($install->log, fn (array $line) => $line['key'] === 'time_retry'));
    }
}
