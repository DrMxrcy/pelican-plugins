<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;

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
     * @throws \Throwable any other daemon error
     */
    public static function read(Server $server, string $path, int $maxBytes): string
    {
        $path = ltrim($path, '/');
        $slash = strrpos($path, '/');
        $directory = $slash === false ? '/' : substr($path, 0, $slash);
        $name = $slash === false ? $path : substr($path, $slash + 1);

        $files = app(DaemonFileRepository::class)->setServer($server);

        try {
            /** @var mixed $listing Wings JSON; not trusted to be a list of entries */
            $listing = $files->getDirectory($directory);
        } catch (\Throwable $exception) {
            // getDirectory() does not declare it, but a Wings 404 arrives as a RequestException.
            throw $exception instanceof RequestException && $exception->response->status() === 404 ? new FileNotFoundException() : $exception;
        }

        $entry = null;
        foreach (is_array($listing) ? $listing : [] as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $name && ($candidate['file'] ?? false) === true) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            throw new FileNotFoundException();
        }
        if (!is_numeric($entry['size'] ?? null) || (int) $entry['size'] > $maxBytes) {
            throw new FileSizeTooLargeException();
        }

        // Still capped: the file may have grown between the listing and the read.
        $content = $files->getContent($path, $maxBytes);
        if (strlen($content) > $maxBytes) {
            throw new FileSizeTooLargeException();
        }

        return $content;
    }
}
