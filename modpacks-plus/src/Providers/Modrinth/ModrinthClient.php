<?php

namespace DrMxrcy\ModpacksPlus\Providers\Modrinth;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * The one place Modrinth HTTP requests are configured: base URL, the unique
 * User-Agent Modrinth asks API clients to send (never the user's email), JSON and
 * timeouts. Also downloads pack archives onto the panel with the host allowlist
 * enforced on every redirect and a byte cap enforced while streaming.
 */
class ModrinthClient
{
    public const API_URL = 'https://api.modrinth.com/v2';

    private const MAX_REDIRECTS = 3;

    protected static ?string $pluginVersion = null;

    public function api(int $timeout = 10): PendingRequest
    {
        return $this->configure(Http::createPendingRequest(), $timeout);
    }

    public function configure(PendingRequest $request, int $timeout = 10): PendingRequest
    {
        return $request
            ->baseUrl(self::API_URL)
            ->withUserAgent($this->userAgent())
            ->asJson()
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(5);
    }

    public function userAgent(): string
    {
        return 'DrMxrcy/modpacks-plus/'.self::pluginVersion().' (github.com/DrMxrcy/pelican-plugins)';
    }

    public static function pluginVersion(): string
    {
        if (self::$pluginVersion === null) {
            $manifest = dirname(__DIR__, 3).'/plugin.json';
            $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

            self::$pluginVersion = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : 'unknown';
        }

        return self::$pluginVersion;
    }

    /**
     * Streams $url into $destination (on the panel). Refuses non-allowlisted URLs and
     * redirects, bodies over $maxBytes (by header and while streaming) and anything other
     * than a 200. The partial file is removed on failure.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function download(string $url, string $destination, int $maxBytes, PlanValidator $validator, int $timeout = 50): void
    {
        if (!$validator->isAllowedUrl($url)) {
            throw new UnsafePlanException('no_allowed_url', $url);
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PackUnavailableException('download_failed', 'cannot create '.$directory);
        }

        try {
            $response = Http::withUserAgent($this->userAgent())
                ->timeout($timeout)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['https'],
                        'on_redirect' => self::redirectGuard($validator),
                    ],
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (int) $length > $maxBytes) {
                            throw new UnsafePlanException('archive_too_large', $length.' bytes');
                        }
                    },
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($downloaded > $maxBytes) {
                            throw new UnsafePlanException('archive_too_large', $downloaded.' bytes');
                        }
                    },
                ])
                ->sink($destination)
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($destination);

            $unsafe = self::unsafeCause($exception);
            if ($unsafe !== null) {
                throw $unsafe;
            }

            throw new PackUnavailableException('download_failed', $url, $exception);
        }

        if ($response->status() !== 200) {
            @unlink($destination);

            throw new PackUnavailableException('download_failed', $url.' (HTTP '.$response->status().')');
        }

        clearstatcache(true, $destination);
        if (!is_file($destination) || filesize($destination) > $maxBytes) {
            @unlink($destination);

            throw new UnsafePlanException('archive_too_large', $url);
        }
    }

    /** Guzzle `on_redirect` callback: every hop must itself be an allowlisted HTTPS URL. */
    public static function redirectGuard(PlanValidator $validator): \Closure
    {
        return function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($validator) {
            if (!$validator->isAllowedUrl((string) $uri)) {
                throw new UnsafePlanException('no_allowed_url', (string) $uri);
            }
        };
    }

    /** Guzzle wraps exceptions thrown from callbacks; find ours. */
    private static function unsafeCause(Throwable $exception): ?UnsafePlanException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UnsafePlanException) {
                return $current;
            }
        }

        return null;
    }
}
