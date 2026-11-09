<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Panel-side downloads run through Guzzle's real redirect middleware (a mock handler
 * below it, no Http::fake above it): every hop must be an allowlisted HTTPS URL, and
 * the byte cap holds.
 */
class ModrinthClientDownloadTest extends TestCase
{
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new HttpFactory());
        $this->destination = sys_get_temp_dir().'/mp-download-'.bin2hex(random_bytes(4)).'/file';
    }

    protected function tearDown(): void
    {
        @unlink($this->destination);
        @rmdir(dirname($this->destination));

        parent::tearDown();
    }

    private function validator(): PlanValidator
    {
        return new PlanValidator(new Caps());
    }

    public function test_follows_a_redirect_to_another_allowlisted_host(): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://objects.githubusercontent.com/release/1?sig=x']),
            new Response(200, [], 'jar-bytes'),
        ]);

        (new ModrinthClient($handler))->download('https://github.com/o/r/releases/download/v1/a.jar', $this->destination, 100, $this->validator());

        $this->assertSame('jar-bytes', file_get_contents($this->destination));
        $this->assertSame('objects.githubusercontent.com', $handler->getLastRequest()?->getUri()->getHost());
    }

    public function test_a_redirect_off_the_allowlist_is_never_followed(): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'secret'),
        ]);

        try {
            (new ModrinthClient($handler))->download('https://github.com/o/r/releases/download/v1/a.jar', $this->destination, 100, $this->validator());
            $this->fail('A redirect off the allowlist was followed');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('no_allowed_url', $exception->reason);
        }

        // The second response (the redirect target) was never requested.
        $this->assertCount(1, $handler);
        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_a_redirect_to_plain_http_is_never_followed(): void
    {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'http://objects.githubusercontent.com/release/1']),
            new Response(200, [], 'body'),
        ]);

        try {
            (new ModrinthClient($handler))->download('https://github.com/o/r/releases/download/v1/a.jar', $this->destination, 100, $this->validator());
            $this->fail('A redirect to plain HTTP was followed');
        } catch (UnsafePlanException|PackUnavailableException) {
            // Refused either by the guard or by Guzzle's protocol check.
        }

        $this->assertCount(1, $handler);
        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_a_body_over_the_cap_is_refused_and_removed(): void
    {
        $handler = new MockHandler([new Response(200, ['Content-Length' => '500'], str_repeat('x', 500))]);

        try {
            (new ModrinthClient($handler))->download('https://github.com/o/r/releases/download/v1/a.jar', $this->destination, 100, $this->validator(), tooLargeReason: 'panel_fetch_too_large');
            $this->fail('An oversized body was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('panel_fetch_too_large', $exception->reason);
        }

        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_a_non_allowlisted_start_url_is_never_requested(): void
    {
        $handler = new MockHandler([new Response(200, [], 'body')]);

        try {
            (new ModrinthClient($handler))->download('https://evil.example/a.jar', $this->destination, 100, $this->validator());
            $this->fail('A non-allowlisted URL was requested');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('no_allowed_url', $exception->reason);
        }

        $this->assertCount(1, $handler);
    }
}
