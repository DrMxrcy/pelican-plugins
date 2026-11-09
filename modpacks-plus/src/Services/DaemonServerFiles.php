<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Exceptions\Repository\FileExistsException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * ServerFiles through the panel's DaemonFileRepository (Wings). The daemon calls run
 * with the server's own credentials, so every path and URL is checked here again,
 * right before it leaves the panel.
 */
class DaemonServerFiles implements ServerFiles
{
    private function repository(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    /** @throws UnsafePlanException */
    private static function guard(string $path, bool $allowRoot = false): string
    {
        if ($allowRoot && $path === '') {
            return '';
        }

        if (!PlanValidator::isSafeRelativePath($path)) {
            throw new UnsafePlanException('unsafe_path', $path);
        }

        return $path;
    }

    public function list(Server $server, string $directory): ?array
    {
        $directory = self::guard($directory, allowRoot: true);

        try {
            $entries = $this->repository($server)->getDirectory('/'.$directory);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return null;
            }

            throw $exception;
        }

        if (isset($entries['error'])) {
            throw new RuntimeException('Daemon error while listing '.$directory);
        }

        $result = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $result[$entry['name']] = [
                    'size' => is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : 0,
                    'directory' => (bool) ($entry['directory'] ?? false),
                ];
            }
        }

        return $result;
    }

    public function stat(Server $server, string $path): ?array
    {
        $path = self::guard($path);
        $directory = dirname($path);

        return $this->list($server, $directory === '.' ? '' : $directory)[basename($path)] ?? null;
    }

    public function createDirectory(Server $server, string $path): void
    {
        $path = self::guard($path);

        $parent = '';
        foreach (explode('/', $path) as $segment) {
            $current = $parent === '' ? $segment : "$parent/$segment";
            $existing = $this->list($server, $parent)[$segment] ?? null;

            if ($existing === null) {
                try {
                    $this->repository($server)->createDirectory($segment, '/'.$parent)->throw();
                } catch (FileExistsException) {
                    // Created meanwhile: fine.
                }
            } elseif (!$existing['directory']) {
                throw new RuntimeException("$current exists and is not a directory");
            }

            $parent = $current;
        }
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $from = self::guard($from);
        $to = self::guard($to);

        if ($this->stat($server, $to) !== null) {
            throw new RuntimeException("Refusing to replace $to");
        }

        $this->repository($server)->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
    }

    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void
    {
        $directory = self::guard($directory, allowRoot: true);
        self::guard($filename);
        if (str_contains($filename, '/')) {
            throw new UnsafePlanException('unsafe_path', $filename);
        }

        if (!(new PlanValidator(new Caps()))->isAllowedUrl($url)) {
            throw new UnsafePlanException('no_allowed_url', $url);
        }

        $this->repository($server)
            ->getHttpClient()
            ->timeout($timeoutSeconds)
            ->post("/api/servers/{$server->uuid}/files/pull", [
                'url' => $url,
                'root' => '/'.$directory,
                'file_name' => $filename,
                'foreground' => true,
            ])
            ->throw();
    }

    public function read(Server $server, string $path, int $maxBytes): string
    {
        $path = self::guard($path);

        $contents = $this->repository($server)->getContent($path, $maxBytes);
        if (strlen($contents) > $maxBytes) {
            throw new FileSizeTooLargeException();
        }

        return $contents;
    }

    public function write(Server $server, string $path, string $contents): void
    {
        $path = self::guard($path);

        $this->repository($server)->putContent($path, $contents)->throw();
    }

    public function delete(Server $server, string $path): void
    {
        $path = self::guard($path);

        $this->repository($server)->deleteFiles('/', [$path])->throw();
    }
}
