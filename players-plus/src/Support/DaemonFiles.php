<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Size-checked reads of tenant files through the daemon. The panel's getContent() downloads
 * the whole body before it looks at Content-Length, so the size is taken from the parent
 * directory listing first and anything over the cap is never downloaded.
 */
final class DaemonFiles
{
    /**
     * @throws FileNotFoundException the file (or its directory) does not exist
     * @throws FileSizeTooLargeException the file is larger than $maxBytes
     * @throws Throwable any other daemon error
     */
    public static function read(Server $server, string $path, int $maxBytes): string
    {
        $stat = self::stat($server, $path);
        if ($stat['size'] > $maxBytes) {
            throw new FileSizeTooLargeException();
        }

        // Still capped: the file may have grown between the listing and the read.
        $content = self::repository($server)->getContent(ltrim($path, '/'), $maxBytes);
        if (strlen($content) > $maxBytes) {
            throw new FileSizeTooLargeException();
        }

        return $content;
    }

    /**
     * Size and modification time from the parent directory listing; nothing is downloaded.
     * The modification time keeps the daemon's UTC offset (null when missing or malformed).
     *
     * @return array{size: int, modified: CarbonImmutable|null}
     *
     * @throws FileNotFoundException the file (or its directory) does not exist
     * @throws FileSizeTooLargeException the listing has no usable size
     * @throws Throwable any other daemon error
     */
    public static function stat(Server $server, string $path): array
    {
        $path = ltrim($path, '/');
        $slash = strrpos($path, '/');
        $directory = $slash === false ? '/' : substr($path, 0, $slash);
        $name = $slash === false ? $path : substr($path, $slash + 1);

        try {
            // Wings JSON: not trusted to be a list of entries.
            $listing = self::repository($server)->getDirectory($directory);
        } catch (Throwable $exception) {
            // getDirectory() does not declare it, but a Wings 404 arrives as a RequestException.
            throw $exception instanceof RequestException && $exception->response->status() === 404 ? new FileNotFoundException() : $exception;
        }

        $entry = null;
        foreach ($listing as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $name && ($candidate['file'] ?? false) === true) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            throw new FileNotFoundException();
        }
        if (!is_numeric($entry['size'] ?? null) || (int) $entry['size'] < 0) {
            throw new FileSizeTooLargeException();
        }

        $modified = null;
        if (is_string($entry['modified'] ?? null)) {
            try {
                $modified = CarbonImmutable::parse($entry['modified']);
            } catch (Throwable) {
                $modified = null;
            }
        }

        return ['size' => (int) $entry['size'], 'modified' => $modified];
    }

    /**
     * Opens the file as a stream, for reading it a line at a time without buffering it.
     * Wings has no range requests, so the stream always starts at byte 0; the caller stops
     * reading (and closes it) when it has what it needs. Check the size with stat() first and
     * bound the bytes read (StreamLines); $timeoutSeconds is only the transport's backstop.
     *
     * @throws FileNotFoundException
     * @throws Throwable any other daemon error
     */
    public static function open(Server $server, string $path, int $timeoutSeconds): StreamInterface
    {
        try {
            $response = self::repository($server)->getHttpClient()
                ->withOptions(['stream' => true])
                ->timeout($timeoutSeconds)
                ->get("/api/servers/$server->uuid/files/contents", ['file' => ltrim($path, '/')]);
        } catch (RequestException $exception) {
            throw $exception->response->status() === 404 ? new FileNotFoundException() : $exception;
        }

        return $response->toPsrResponse()->getBody();
    }

    private static function repository(Server $server): DaemonFileRepository
    {
        $repository = app(DaemonFileRepository::class);
        $repository->setServer($server);

        return $repository;
    }
}
