<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only JSON GETs against one fixed HTTPS base (FTB's and ATLauncher's APIs, plan 32),
 * with the same bounds as CurseForgeClient / ModrinthClient:
 * - the honest plugin User-Agent (DrMxrcy/modpacks-plus/<version>), never a browser's;
 * - `Accept-Encoding: identity`, never decoded (a small compressed body could inflate to
 *   gigabytes); an encoded answer is refused before its body is read;
 * - answers capped by Content-Length, while streaming and again before parsing;
 * - redirects never followed (the base decides the host, an answer can't move it), so a
 *   request is one hop, bounded by its timeout, itself bounded by the job's time (JobClock);
 * - paths are a list of plain segments (letters, digits, `.`, `_`, `+`, `-`; never `.`/`..`),
 *   so a pack-supplied id can't reach another path or host.
 *
 * Every failure is a PackUnavailableException with a stable reason; nothing is retried.
 */
final class JsonApiClient
{
    /** Largest answer read: a hostile or broken API can't make a request hold an unbounded body. */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9_+\-][A-Za-z0-9._+\-]{0,127}$/';

    /**
     * @param  string  $baseUrl  https://host[/path], no trailing slash
     * @param  string  $unavailable  reason for an unreachable or broken API
     * @param  string  $notFound  reason for a 404
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $unavailable,
        private readonly string $notFound,
        private readonly int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
        private readonly mixed $handler = null,
    ) {}

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Whether $segment may be one path segment of a request. */
    public static function isSafeSegment(string $segment): bool
    {
        return (bool) preg_match(self::SEGMENT_PATTERN, $segment) && !str_contains($segment, '..');
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    public function get(array $segments, array $query = [], int $timeout = 10): array
    {
        if ($segments === [] || array_filter($segments, fn (string $segment) => !self::isSafeSegment($segment)) !== []) {
            throw new PackUnavailableException($this->unavailable, 'invalid path');
        }

        $path = implode('/', $segments);
        $url = $this->baseUrl.'/'.$path;

        try {
            $request = Http::createPendingRequest();
            if ($this->handler !== null) {
                // Tests: a Guzzle handler below the real redirect middleware.
                $request->setHandler($this->handler);
            }

            $response = $request
                ->withUserAgent(app(ModrinthClient::class)->userAgent())
                ->acceptJson()
                // Never more than the running install job has left (JobClock).
                ->timeout(JobClock::timeout($timeout))
                ->connectTimeout(5)
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions(['allow_redirects' => false] + ModrinthClient::boundedOptions($this->maxResponseBytes))
                ->beforeSending(function (Request $request) {
                    if (!str_starts_with($request->url(), $this->baseUrl.'/')) {
                        throw new \RuntimeException('blocked');
                    }
                })
                ->get($url, $query);
        } catch (Throwable) {
            throw new PackUnavailableException($this->unavailable, $path);
        }

        return $this->json($response, $path);
    }

    /**
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function json(Response $response, string $path): array
    {
        $status = $response->status();
        if ($status === 404) {
            throw new PackUnavailableException($this->notFound, $path);
        }

        if (!ModrinthClient::isIdentityEncoding($response->header('Content-Encoding'))) {
            throw new PackUnavailableException($this->unavailable, $path.' (encoded)');
        }

        $length = $response->header('Content-Length');
        $size = $response->toPsrResponse()->getBody()->getSize();
        if (($length !== '' && (int) $length > $this->maxResponseBytes) || $size === null || $size > $this->maxResponseBytes) {
            throw new PackUnavailableException($this->unavailable, $path.' (too large)');
        }

        $data = $status >= 200 && $status < 300 ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException($this->unavailable, $path.' (HTTP '.$status.')');
        }

        return $data;
    }
}
