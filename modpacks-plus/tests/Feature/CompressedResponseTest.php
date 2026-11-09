<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Tests\Support\CurseForge;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Plan 31 security review: compression bombs. Panel requests ask for identity encoding,
 * never decode a compressed body (byte caps count what is held, not what is on the wire)
 * and refuse an encoded answer before its body is read.
 */
class CompressedResponseTest extends TestCase
{
    /** A gzip body of ~16 KB that inflates to 64 MB. */
    private static function bomb(): string
    {
        return (string) gzencode(str_repeat('{"data":[]}', 6 * 1024 * 1024), 9);
    }

    public function test_curseforge_asks_for_identity_and_refuses_a_compressed_answer(): void
    {
        $bomb = self::bomb();
        $this->assertLessThan(1024 * 1024, strlen($bomb));
        Http::fake([CurseForge::API.'*' => Http::response($bomb, 200, ['Content-Encoding' => 'gzip', 'Content-Type' => 'application/json'])]);

        try {
            (new CurseForgeClient(CurseForge::KEY))->get('mods/1');
            $this->fail('A compressed answer was accepted');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('curseforge_unavailable', $exception->reason);
            $this->assertStringContainsString('encoded', $exception->detail);
        }

        Http::assertSent(fn (Request $request) => $request->header('Accept-Encoding') === ['identity']);
    }

    public function test_the_mirror_refuses_a_compressed_answer(): void
    {
        Http::fake(['api.curse.tools/*' => Http::response(self::bomb(), 200, ['Content-Encoding' => 'GZIP'])]);

        try {
            (new CurseForgeClient(null, true))->get('mods/1');
            $this->fail('A compressed answer was accepted');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('curseforge_mirror_unavailable', $exception->reason);
        }
    }

    public function test_bounded_options_never_decode_and_refuse_encoded_or_oversized_answers_on_the_real_handler_stack(): void
    {
        $options = ModrinthClient::boundedOptions(1024);
        $this->assertFalse($options['decode_content']);

        foreach ([
            'gzip' => new Response(200, ['Content-Encoding' => 'gzip'], self::bomb()),
            'deflate' => new Response(200, ['Content-Encoding' => 'deflate'], 'x'),
            'declared too large' => new Response(200, ['Content-Length' => '4096'], 'x'),
        ] as $case => $response) {
            $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
            try {
                $client->get('https://api.modrinth.com/v2/x', $options);
                $this->fail("$case was accepted");
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('refused', $exception->getMessage().($exception->getPrevious()?->getMessage() ?? ''), $case);
            }
        }

        // Plain, small answers pass.
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]);
        $this->assertSame('{}', (string) $client->get('https://api.modrinth.com/v2/x', $options)->getBody());
    }

    public function test_modrinth_api_requests_ask_for_identity_and_never_decode(): void
    {
        $handler = new MockHandler([new Response(200, ['Content-Encoding' => 'gzip'], self::bomb())]);
        $request = (new ModrinthClient())->configure(Http::createPendingRequest()->setHandler(HandlerStack::create($handler)));

        try {
            $request->get('version/x');
            $this->fail('A compressed Modrinth answer was accepted');
        } catch (\Throwable) {
            // Refused in on_headers, before the body is read.
        }

        $this->assertSame('identity', $handler->getLastRequest()?->getHeaderLine('Accept-Encoding'));
        $this->assertFalse($handler->getLastOptions()['decode_content'] ?? null);
    }

    public function test_panel_downloads_refuse_a_compressed_body(): void
    {
        Http::swap(new HttpFactory());
        $destination = sys_get_temp_dir().'/mp-gzip-'.bin2hex(random_bytes(4)).'/file';
        $handler = new MockHandler([new Response(200, ['Content-Encoding' => 'gzip'], self::bomb())]);

        try {
            (new ModrinthClient($handler))->download('https://edge.forgecdn.net/files/1/2/a.jar', $destination, 1024 * 1024, new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor('curseforge')));
            $this->fail('A compressed download was accepted');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('download_failed', $exception->reason);
        }

        $this->assertFileDoesNotExist($destination);
        $this->assertSame('identity', $handler->getLastRequest()?->getHeaderLine('Accept-Encoding'));
        $this->assertFalse($handler->getLastOptions()['decode_content'] ?? null);
        @rmdir(dirname($destination));
    }
}
