<?php

namespace DrMxrcy\ModpacksPlus\Providers\CurseForge;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one place CurseForge API requests are made (metadata only: search, mod and file
 * info, manifest resolution; downloads never go through here).
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

    public const KEY_ENV = 'MODPACKS_PLUS_CURSEFORGE_API_KEY';

    /** What CurseForge keys look like ($2a$10$… plus letters, digits, . and /), with room to spare. */
    public const KEY_PATTERN = '#^[A-Za-z0-9$./_+=\-]{16,128}$#';

    public const MODE_KEY = 'key';

    /** Relative API paths: lowercase words and numeric ids only (no scheme, host, `..`, query). */
    private const PATH_PATTERN = '#^[a-z]+(/[a-z0-9]+)*$#';

    private readonly ?string $apiKey;

    public function __construct(#[\SensitiveParameter] ?string $apiKey = null)
    {
        $this->apiKey = $apiKey !== null && self::isValidKey($apiKey) ? $apiKey : null;
    }

    public static function fromConfig(): self
    {
        $key = config('modpacks-plus.curseforge.api_key');

        return new self(is_string($key) ? $key : null);
    }

    public static function isValidKey(#[\SensitiveParameter] string $key): bool
    {
        return (bool) preg_match(self::KEY_PATTERN, $key);
    }

    /** How CurseForge is reached: MODE_KEY, or null when it is not set up (nothing is ever requested then). */
    public function mode(): ?string
    {
        return $this->apiKey !== null ? self::MODE_KEY : null;
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

    /** Whether $url is on the API base (exact origin and path prefix). */
    public static function isApiUrl(string $url, string $base): bool
    {
        return str_starts_with($url, $base.'/') && !preg_match('/[\s\\\\@]/', $url);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function send(string $method, string $path, array $payload, int $timeout): array
    {
        if ($this->mode() === null) {
            throw new PackUnavailableException('curseforge_not_configured');
        }

        if (!preg_match(self::PATH_PATTERN, $path)) {
            throw new PackUnavailableException('curseforge_unavailable', 'invalid path');
        }

        $base = self::API_URL;

        try {
            $request = Http::createPendingRequest()
                ->baseUrl($base)
                ->withUserAgent('DrMxrcy/modpacks-plus/'.ModrinthClient::pluginVersion().' (github.com/DrMxrcy/pelican-plugins)')
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(5)
                // A redirect would carry the key header to wherever it points.
                ->withOptions(['allow_redirects' => false])
                ->beforeSending(function (Request $request) use ($base) {
                    if (!self::isApiUrl($request->url(), $base)) {
                        throw new \RuntimeException('blocked');
                    }
                })
                ->withHeaders(['x-api-key' => (string) $this->apiKey]);

            $response = $method === 'GET' ? $request->get($path, $payload) : $request->asJson()->post($path, $payload);
        } catch (Throwable) {
            // Deliberately not chained (see the class comment).
            throw new PackUnavailableException('curseforge_unavailable', $path);
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
            throw new PackUnavailableException('curseforge_not_found', $path);
        }

        if ($status === 401 || $status === 403) {
            throw new PackUnavailableException('curseforge_key_rejected', $path.' (HTTP '.$status.')');
        }

        $data = $status >= 200 && $status < 300 ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException('curseforge_unavailable', $path.' (HTTP '.$status.')');
        }

        return $data;
    }
}
