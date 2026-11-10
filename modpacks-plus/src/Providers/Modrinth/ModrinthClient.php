<?php

namespace DrMxrcy\ModpacksPlus\Providers\Modrinth;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\ByteLimitFilter;
use DrMxrcy\ModpacksPlus\Support\JobClock;
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

    /**
     * Redirect hops a panel download may follow (plan 32 security review: one is all the CDNs
     * need — edge.forgecdn.net → mediafilez, github.com → objects.githubusercontent.com — and
     * every extra hop is another request inside the same job budget).
     */
    public const MAX_REDIRECTS = 1;

    /** Largest Modrinth API answer read (header and while streaming). */
    public const MAX_API_BYTES = 16 * 1024 * 1024;

    /** Seconds one panel-side download may take (keeps a job well under ~60 s). */
    public const DOWNLOAD_TIMEOUT = 30;

    protected static ?string $pluginVersion = null;

    /**
     * @param  callable|null  $downloadHandler  Guzzle handler for download() (tests use a mock
     *                                          handler so the real redirect middleware runs)
     */
    public function __construct(private readonly mixed $downloadHandler = null) {}

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
            ->timeout(JobClock::timeout($timeout))
            ->connectTimeout(5)
            // Never inflate (a small compressed body could decode to gigabytes); cap what is read.
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions(self::boundedOptions(self::MAX_API_BYTES));
    }

    /** Whether a Content-Encoding header means the body is sent as is. */
    public static function isIdentityEncoding(string $encoding): bool
    {
        $encoding = strtolower(trim($encoding));

        return $encoding === '' || $encoding === 'identity';
    }

    /**
     * Guzzle options that never decode a compressed body and stop reading past $maxBytes
     * (Content-Length and while streaming); an encoded answer is refused outright.
     *
     * @return array<string, mixed>
     */
    public static function boundedOptions(int $maxBytes): array
    {
        return [
            'decode_content' => false,
            'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                $length = $response->getHeaderLine('Content-Length');
                if (($length !== '' && (int) $length > $maxBytes) || !self::isIdentityEncoding($response->getHeaderLine('Content-Encoding'))) {
                    throw new \RuntimeException('refused');
                }
            },
            'progress' => function ($total, $downloaded) use ($maxBytes) {
                if ($downloaded > $maxBytes) {
                    throw new \RuntimeException('too large');
                }
            },
        ];
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
     * redirects, bodies over $maxBytes (by header, while streaming and by a byte-limit filter
     * on the file itself, so it doesn't depend on how often the handler reports progress) and
     * anything other than a 200. At most $maxRedirects hops (0 = none). $timeout is one
     * deadline shared by every hop (checked on each redirect and while streaming) and is never
     * more than the running job has left (JobClock). The partial file is removed on failure.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function download(string $url, string $destination, int $maxBytes, PlanValidator $validator, int $timeout = self::DOWNLOAD_TIMEOUT, string $tooLargeReason = 'archive_too_large', int $maxRedirects = self::MAX_REDIRECTS): void
    {
        if (!$validator->isAllowedUrl($url)) {
            throw new UnsafePlanException('no_allowed_url', $url);
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PackUnavailableException('download_failed', 'cannot create '.$directory);
        }

        $timeout = JobClock::timeout($timeout);
        $deadline = microtime(true) + $timeout;
        $timedOut = function () use ($deadline, $url) {
            if (microtime(true) > $deadline) {
                throw new PackUnavailableException('download_failed', $url.' (timed out)');
            }
        };
        $guard = self::redirectGuard($validator);

        $sink = @fopen($destination, 'w+b');
        if ($sink === false) {
            throw new PackUnavailableException('download_failed', 'cannot write '.basename($destination));
        }

        try {
            $limit = ByteLimitFilter::attach($sink, $maxBytes);

            $request = Http::createPendingRequest();
            if ($this->downloadHandler !== null) {
                $request->setHandler($this->downloadHandler);
            }

            $response = $request
                ->withUserAgent($this->userAgent())
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->withOptions([
                    // Never inflate: the byte cap is about what lands on the panel's disk.
                    'decode_content' => false,
                    'allow_redirects' => $maxRedirects < 1 ? false : [
                        'max' => $maxRedirects,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['https'],
                        'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($guard, $timedOut) {
                            $guard($request, $response, $uri);
                            // One deadline for the whole chain, not one timeout per hop.
                            $timedOut();
                        },
                    ],
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes, $tooLargeReason, $url) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (int) $length > $maxBytes) {
                            throw new UnsafePlanException($tooLargeReason, $url);
                        }
                        if (!self::isIdentityEncoding($response->getHeaderLine('Content-Encoding'))) {
                            throw new PackUnavailableException('download_failed', $url.' (encoded)');
                        }
                    },
                    'progress' => function ($total, $downloaded) use ($maxBytes, $tooLargeReason, $url, $timedOut) {
                        if ($downloaded > $maxBytes) {
                            throw new UnsafePlanException($tooLargeReason, $url);
                        }
                        $timedOut();
                    },
                ])
                ->sink($sink)
                ->get($url);
        } catch (Throwable $exception) {
            self::close($sink);
            @unlink($destination);

            $unsafe = self::unsafeCause($exception);
            if ($unsafe !== null) {
                throw $unsafe;
            }
            if (isset($limit) && $limit->exceeded) {
                throw new UnsafePlanException($tooLargeReason, $url);
            }

            throw new PackUnavailableException('download_failed', $url, $exception);
        }
        self::close($sink);

        if ($limit->exceeded) {
            @unlink($destination);

            throw new UnsafePlanException($tooLargeReason, $url);
        }

        if ($response->status() !== 200 || !self::isIdentityEncoding($response->header('Content-Encoding'))) {
            @unlink($destination);

            throw new PackUnavailableException('download_failed', $url.' (HTTP '.$response->status().')');
        }

        clearstatcache(true, $destination);
        if (!is_file($destination) || filesize($destination) > $maxBytes) {
            @unlink($destination);

            throw new UnsafePlanException($tooLargeReason, $url);
        }
    }

    /**
     * The size $url's host gives for it (one HEAD request, its Content-Length), for a file whose
     * source names no size (plan 32 step 6: ATLauncher's md5-checked CDN files). Null when the
     * answer is not a 200 with a plain, uncompressed length (a redirect is never followed); the
     * file is then listed instead of installed. The size only caps and checks the later download:
     * the source's hash still decides whether the bytes are used.
     *
     * @throws UnsafePlanException $url is off the allowlist (nothing is requested)
     * @throws PackUnavailableException the host failed, timed out or is busy (worth another attempt)
     */
    public function contentLength(string $url, PlanValidator $validator, int $timeout = 20): ?int
    {
        if (!$validator->isAllowedUrl($url)) {
            throw new UnsafePlanException('no_allowed_url', $url);
        }

        $timeout = JobClock::timeout($timeout);
        try {
            $request = Http::createPendingRequest();
            if ($this->downloadHandler !== null) {
                $request->setHandler($this->downloadHandler);
            }

            $response = $request
                ->withUserAgent($this->userAgent())
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->withOptions(['allow_redirects' => false, 'decode_content' => false])
                ->head($url);
        } catch (Throwable $exception) {
            throw new PackUnavailableException('download_failed', $url, $exception);
        }

        if ($response->status() === 429 || $response->status() >= 500) {
            throw new PackUnavailableException('download_failed', $url.' (HTTP '.$response->status().')');
        }

        $length = $response->header('Content-Length');
        if ($response->status() !== 200 || !self::isIdentityEncoding($response->header('Content-Encoding')) || !preg_match('/^\d{1,15}$/D', $length)) {
            return null;
        }

        return (int) $length;
    }

    private static function close(mixed $handle): void
    {
        if (is_resource($handle)) {
            @fclose($handle);
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
