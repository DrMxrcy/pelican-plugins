<?php

namespace DrMxrcy\ModpacksPlus\Providers\CurseForge;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one place CurseForge API requests are made (metadata only: search, mod and file
 * info, manifest resolution; downloads never go through here).
 *
 * Two ways in: the admin's API key (api.curseforge.com), or, when no key is set and the
 * admin opted in, the public key-free mirror https://api.curse.tools/v1 (same routes and
 * answers). A configured key always wins. The mirror's `/v1/cf/...` form redirects to plain
 * HTTP, so a `cf` first segment is refused for both, and redirects are never followed. A
 * mirror that fails or rate-limits makes CurseForge "unavailable": nothing is retried
 * elsewhere and no install starts. Mirror answers are as untrusted as any API answer:
 * downloads still only come from allowlisted hosts with hash checks.
 *
 * Threat handled: leaking the admin's API key. The key is only ever attached to requests
 * whose URL starts with https://api.curseforge.com/v1/ (checked on the built path and
 * again right before sending), redirects are never followed (a redirect would carry the
 * header to another host), and it never ends up in an exception: transport errors are
 * replaced by a PackUnavailableException without the original as `previous`, because
 * Guzzle's request (and with it the x-api-key header) hangs off that chain. Paths are a
 * fixed set of lowercase segments and numeric ids, so a pack-supplied value can't make
 * the client call another host.
 */
final class CurseForgeClient
{
    public const API_URL = 'https://api.curseforge.com/v1';

    public const MIRROR_URL = 'https://api.curse.tools/v1';

    public const MIRROR_ENV = 'MODPACKS_PLUS_CURSEFORGE_MIRROR';

    public const KEY_ENV = 'MODPACKS_PLUS_CURSEFORGE_API_KEY';

    /** What CurseForge keys look like ($2a$10$… plus letters, digits, . and /), with room to spare. */
    public const KEY_PATTERN = '#^[A-Za-z0-9$./_+=\-]{16,128}$#';

    public const MODE_KEY = 'key';

    public const MODE_MIRROR = 'mirror';

    /**
     * Largest API answer read (header and while streaming): a hostile or broken API or mirror
     * can't make a page request or a queue worker hold an unbounded body in memory.
     */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /** Relative API paths: lowercase words and numeric ids only (no scheme, host, `..`, query). */
    private const PATH_PATTERN = '#^[a-z]+(/[a-z0-9]+)*$#';

    private readonly ?string $apiKey;

    public function __construct(#[\SensitiveParameter] ?string $apiKey = null, private readonly bool $mirror = false, private readonly int $maxResponseBytes = self::MAX_RESPONSE_BYTES)
    {
        $this->apiKey = $apiKey !== null && self::isValidKey($apiKey) ? $apiKey : null;
    }

    public static function fromConfig(): self
    {
        $key = config('modpacks-plus.curseforge.api_key');

        return new self(is_string($key) ? $key : null, (bool) config('modpacks-plus.curseforge.mirror', false));
    }

    public static function isValidKey(#[\SensitiveParameter] string $key): bool
    {
        return (bool) preg_match(self::KEY_PATTERN, $key);
    }

    /** How CurseForge is reached: MODE_KEY (wins), MODE_MIRROR, or null when it is not set up (nothing is ever requested then). */
    public function mode(): ?string
    {
        return match (true) {
            $this->apiKey !== null => self::MODE_KEY,
            $this->mirror => self::MODE_MIRROR,
            default => null,
        };
    }

    public function baseUrl(): ?string
    {
        return match ($this->mode()) {
            self::MODE_KEY => self::API_URL,
            self::MODE_MIRROR => self::MIRROR_URL,
            default => null,
        };
    }

    public function configured(): bool
    {
        return $this->mode() !== null;
    }

    /** Never the key, even in dumps. */
    public function __debugInfo(): array
    {
        return ['mode' => $this->mode()];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    public function get(string $path, array $query = [], int $timeout = 10): array
    {
        return $this->send('GET', $path, $query, $timeout);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    public function post(string $path, array $body, int $timeout = 20): array
    {
        return $this->send('POST', $path, $body, $timeout);
    }

    /** Whether $url is on the API base (exact origin and path prefix), never the mirror's redirecting `/cf` form. */
    public static function isApiUrl(string $url, string $base): bool
    {
        return str_starts_with($url, $base.'/') && !preg_match('/[\s\\\\@]/', $url)
            && !preg_match('#^/cf(/|\?|$)#i', substr($url, strlen($base)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function send(string $method, string $path, array $payload, int $timeout): array
    {
        $mode = $this->mode();
        $base = $this->baseUrl();
        if ($mode === null || $base === null) {
            throw new PackUnavailableException('curseforge_not_configured');
        }

        if (!preg_match(self::PATH_PATTERN, $path) || $path === 'cf' || str_starts_with($path, 'cf/')) {
            throw new PackUnavailableException('curseforge_unavailable', 'invalid path');
        }

        try {
            $request = Http::createPendingRequest()
                ->baseUrl($base)
                ->withUserAgent('DrMxrcy/modpacks-plus/'.ModrinthClient::pluginVersion().' (github.com/DrMxrcy/pelican-plugins)')
                ->acceptJson()
                ->timeout(JobClock::timeout($timeout))
                ->connectTimeout(5)
                // A redirect would carry the key header to wherever it points. Never inflate (a small
                // compressed body could decode to gigabytes); stop reading past the cap.
                ->withOptions(['allow_redirects' => false] + ModrinthClient::boundedOptions($this->maxResponseBytes))
                ->beforeSending(function (Request $request) use ($base) {
                    if (!self::isApiUrl($request->url(), $base)) {
                        throw new \RuntimeException('blocked');
                    }
                })
                // The key only ever goes to api.curseforge.com; the mirror gets no key at all.
                ->withHeaders(['Accept-Encoding' => 'identity'] + ($mode === self::MODE_KEY ? ['x-api-key' => (string) $this->apiKey] : []));

            $response = $method === 'GET' ? $request->get($path, $payload) : $request->asJson()->post($path, $payload);
        } catch (Throwable) {
            // Deliberately not chained (see the class comment).
            throw new PackUnavailableException($this->unavailable(), $path);
        }

        return $this->json($response, $path);
    }

    /** The reason for an unreachable API: the mirror's says "add an API key". */
    private function unavailable(): string
    {
        return $this->mode() === self::MODE_MIRROR ? 'curseforge_mirror_unavailable' : 'curseforge_unavailable';
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
            throw new PackUnavailableException('curseforge_not_found', $path);
        }

        if (($status === 401 || $status === 403) && $this->mode() === self::MODE_KEY) {
            throw new PackUnavailableException('curseforge_key_rejected', $path.' (HTTP '.$status.')');
        }

        // Checked again before the body is touched: an encoded body is never decoded, and handlers
        // that don't report progress (and fakes) still can't hand over more than the cap.
        if (!ModrinthClient::isIdentityEncoding($response->header('Content-Encoding'))) {
            throw new PackUnavailableException($this->unavailable(), $path.' (encoded)');
        }
        $length = $response->header('Content-Length');
        $size = $response->toPsrResponse()->getBody()->getSize();
        if (($length !== '' && (int) $length > $this->maxResponseBytes) || $size === null || $size > $this->maxResponseBytes) {
            throw new PackUnavailableException($this->unavailable(), $path.' (too large)');
        }

        $data = $status >= 200 && $status < 300 ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException($this->unavailable(), $path.' (HTTP '.$status.')');
        }

        return $data;
    }
}
