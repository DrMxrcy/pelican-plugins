<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Exceptions\Repository\FileExistsException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use DrMxrcy\ModpacksPlus\Support\OverrideArchive;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * ServerFiles through the panel's DaemonFileRepository (Wings). The daemon calls run
 * with the server's own credentials, so every path and URL is checked here again,
 * right before it leaves the panel.
 */
class DaemonServerFiles implements ServerFiles
{
    /** The only host the node is ever asked to pull from. */
    public const PULL_HOST = 'cdn.modrinth.com';

    /** Seconds the panel waits for the node to unpack an override archive (capped by the job's time left). */
    public const DECOMPRESS_TIMEOUT = 45;

    /** @param  array<int, string>  $worlds */
    final public function __construct(private readonly array $worlds = ['world']) {}

    public function protecting(array $worlds): static
    {
        return new static(array_values(array_unique(array_merge($this->worlds, $worlds))));
    }

    private function repository(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    /**
     * A path the engine may change: safe, and not a world, player list or
     * server.properties. `.modpacks-plus/` (staging, moved-aside files) is the plugin's own.
     *
     * @throws UnsafePlanException
     */
    private function guardTarget(string $path, bool $creatingDirectory = false): string
    {
        self::guard($path);

        $own = $path === PlanValidator::OWN_FOLDER || str_starts_with($path, PlanValidator::OWN_FOLDER.'/');
        $validator = new PlanValidator(new Caps(), $this->worlds);
        // Creating a directory that merely contains a world (e.g. `config` for `config/world`) is harmless.
        $protected = $creatingDirectory ? $validator->isWithinProtected($path) : $validator->isProtected($path);
        if (!$own && $protected) {
            throw new UnsafePlanException('protected_path', $path);
        }

        return $path;
    }

    /** @throws UnsafePlanException */
    public static function assertPullable(string $url): void
    {
        if (!(new PlanValidator(new Caps()))->isAllowedUrl($url) || parse_url($url, PHP_URL_HOST) !== self::PULL_HOST) {
            throw new UnsafePlanException('no_allowed_url', $url);
        }
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
                $item = [
                    'size' => is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : 0,
                    'directory' => (bool) ($entry['directory'] ?? false),
                ];
                $modified = self::modifiedTime($entry['modified'] ?? null);
                if ($modified !== null) {
                    $item['modified'] = $modified;
                }
                $result[$entry['name']] = $item;
            }
        }

        return $result;
    }

    /** Wings reports `modified` as an RFC 3339 time; anything unreadable is left out. */
    private static function modifiedTime(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false || $time <= 0 ? null : $time;
    }

    public function stat(Server $server, string $path): ?array
    {
        $path = self::guard($path);
        $directory = dirname($path);

        return $this->list($server, $directory === '.' ? '' : $directory)[basename($path)] ?? null;
    }

    public function createDirectory(Server $server, string $path): void
    {
        $path = $this->guardTarget($path, creatingDirectory: true);

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
        $from = $this->guardTarget($from);
        $to = $this->guardTarget($to);

        if ($this->stat($server, $to) !== null) {
            throw new RuntimeException("Refusing to replace $to");
        }

        $this->repository($server)->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
    }

    public function renameMany(Server $server, array $pairs): void
    {
        if (count($pairs) > self::RENAME_BATCH) {
            throw new RuntimeException('Too many renames in one request');
        }

        $files = [];
        foreach ($pairs as $pair) {
            $files[] = ['from' => $this->guardTarget($pair['from']), 'to' => $this->guardTarget($pair['to'])];
        }
        if ($files === []) {
            return;
        }

        // Never replaces: each target directory is listed once instead of a stat per pair.
        $listings = [];
        $targets = [];
        foreach ($files as ['to' => $to]) {
            $directory = dirname($to);
            $directory = $directory === '.' ? '' : $directory;
            if (!array_key_exists($directory, $listings)) {
                $listings[$directory] = $this->list($server, $directory) ?? [];
            }
            if (isset($listings[$directory][basename($to)]) || isset($targets[$to])) {
                throw new RuntimeException("Refusing to replace $to");
            }
            $targets[$to] = true;
        }

        $this->repository($server)->renameFiles('/', $files)->throw();
    }

    public function movePreserved(Server $server, string $from, string $to): void
    {
        self::guardPreservedMove($from, $to);

        if ($this->stat($server, $to) !== null) {
            throw new RuntimeException("Refusing to replace $to");
        }

        $this->repository($server)->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
    }

    /** Own subfolders a preserved move may use (never staging/). */
    public const PRESERVED_MOVE_PREFIXES = ['preserved-', 'replaced-', 'previous-'];

    /**
     * A move between the root and `.modpacks-plus/{preserved,replaced,previous}-…/…`: both
     * paths safe, exactly one of them inside such a folder, the other not the own folder.
     *
     * @throws UnsafePlanException
     */
    public static function guardPreservedMove(string $from, string $to): void
    {
        self::guard($from);
        self::guard($to);

        $inside = function (string $path): bool {
            $segments = explode('/', $path);
            if (count($segments) < 3 || $segments[0] !== PlanValidator::OWN_FOLDER) {
                return false;
            }
            foreach (self::PRESERVED_MOVE_PREFIXES as $prefix) {
                if (str_starts_with($segments[1], $prefix) && strlen($segments[1]) > strlen($prefix)) {
                    return true;
                }
            }

            return false;
        };
        $touchesOwn = fn (string $path) => $path === PlanValidator::OWN_FOLDER || str_starts_with($path, PlanValidator::OWN_FOLDER.'/');

        $fromInside = $inside($from);
        $toInside = $inside($to);
        if ($fromInside === $toInside || (!$fromInside && $touchesOwn($from)) || (!$toInside && $touchesOwn($to))) {
            throw new UnsafePlanException('protected_path', "$from -> $to");
        }
    }

    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void
    {
        $directory = self::guard($directory, allowRoot: true);
        if (str_contains($filename, '/')) {
            throw new UnsafePlanException('unsafe_path', $filename);
        }
        $this->guardTarget($directory === '' ? $filename : "$directory/$filename");

        self::assertPullable($url);

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

    public function write(Server $server, string $path, string $contents, ?int $timeoutSeconds = null): void
    {
        $path = $this->guardTarget($path);

        if ($timeoutSeconds === null) {
            $this->repository($server)->putContent($path, $contents)->throw();

            return;
        }

        // DaemonFileRepository::putContent()'s request, with a timeout the job can afford (plan 42).
        $this->repository($server)
            ->getHttpClient()
            ->timeout(JobClock::timeout($timeoutSeconds))
            ->withQueryParameters(['file' => $path])
            ->withBody($contents)
            ->post("/api/servers/{$server->uuid}/files/write")
            ->throw();
    }

    public function writeFile(Server $server, string $path, string $localFile, ?int $timeoutSeconds = null): void
    {
        $path = $this->guardTarget($path);

        $handle = @fopen($localFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the file to write.');
        }

        // A seekable file stream: Guzzle sends its Content-Length, nothing is buffered in memory.
        // The stream owns the handle and closes it when it is released.
        $body = Utils::streamFor($handle);

        $request = $this->repository($server)->getHttpClient();
        if ($timeoutSeconds !== null) {
            $request = $request->timeout(JobClock::timeout($timeoutSeconds));
        }
        $request
            ->withQueryParameters(['file' => $path])
            ->withBody($body, 'application/octet-stream')
            ->post("/api/servers/{$server->uuid}/files/write")
            ->throw();
    }

    public function delete(Server $server, string $path): void
    {
        $path = $this->guardTarget($path);

        $this->repository($server)->deleteFiles('/', [$path])->throw();
    }

    /**
     * Plan 42. The node's unpack writes every entry without asking the panel, so this guard is
     * what a per-file write's guardTarget() is: the archive must be a staged override archive
     * and each entry a safe path that is not protected (worlds, player lists, server.properties)
     * and, unlike a single write, not inside the plugin's own folder either.
     *
     * @param  array<int, mixed>  $paths  checked as untrusted input (a non-string is refused, never coerced)
     * @param  array<int, string>  $worlds
     *
     * @throws UnsafePlanException
     */
    public static function guardDecompress(string $archive, array $paths, array $worlds = ['world']): void
    {
        self::guard($archive);
        if (!OverrideArchive::isStagingPath($archive)) {
            throw new UnsafePlanException('unsafe_path', $archive);
        }

        if ($paths === [] || count($paths) > OverrideArchive::CHUNK_ENTRIES) {
            throw new UnsafePlanException('too_many_entries', (string) count($paths));
        }

        $validator = new PlanValidator(new Caps(), $worlds);
        $seen = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new UnsafePlanException('unsafe_path', $archive);
            }
            self::guard($path);

            // isProtected() covers `.modpacks-plus/` itself (no own-folder exception here).
            if ($validator->isProtected($path) || PlanValidator::isModrinthMetadata($path)) {
                throw new UnsafePlanException('protected_path', $path);
            }

            if (isset($seen[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $seen[strtolower($path)] = true;
        }
    }

    public function decompress(Server $server, string $archive, array $paths): void
    {
        self::guardDecompress($archive, $paths, $this->worlds);

        // Not DaemonFileRepository::decompressFile(): its fixed 15-minute timeout would outlive the job.
        $this->repository($server)
            ->getHttpClient()
            ->timeout(JobClock::timeout(self::DECOMPRESS_TIMEOUT))
            ->post("/api/servers/{$server->uuid}/files/decompress", [
                'root' => '/',
                'file' => $archive,
            ])
            ->throw();
    }
}
