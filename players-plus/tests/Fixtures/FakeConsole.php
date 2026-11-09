<?php

namespace DrMxrcy\PlayersPlus\Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Answers the daemon's server-details and send-command endpoints the way Wings does, so
 * moderation tests can see exactly which console lines would have been sent.
 */
final class FakeConsole
{
    /** Container state reported by the details endpoint (running, offline, starting...). */
    public string $state = 'running';

    /** Status the send-command endpoint answers with (Wings: 204, or 502 when not running). */
    public int $commandStatus = 204;

    public bool $detailsDown = false;

    public function respond(Request $request): ?PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && preg_match('#^/api/servers/[^/]+/commands$#', $path) === 1) {
            return Http::response('', $this->commandStatus);
        }

        if ($request->method() === 'GET' && preg_match('#^/api/servers/[^/]+$#', $path) === 1) {
            return $this->detailsDown ? Http::response('', 500) : Http::response(['state' => $this->state, 'utilization' => []]);
        }

        return null;
    }

    /**
     * Every console line sent so far, in order.
     *
     * @return list<string>
     */
    public static function sent(): array
    {
        $lines = [];
        $posts = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/commands'));
        foreach ($posts as [$request]) {
            foreach ((array) ($request->data()['commands'] ?? []) as $line) {
                $lines[] = (string) $line;
            }
        }

        return $lines;
    }
}
