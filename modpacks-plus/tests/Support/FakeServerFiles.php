<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * In-memory server filesystem standing in for the daemon. It records every call and
 * every safety violation (a path outside the root, a non-allowlisted pull) instead of
 * trusting the code under test to have checked.
 */
final class FakeServerFiles implements ServerFiles
{
    /** @var array<string, string> path => contents */
    public array $files = [];

    /** @var array<string, true> */
    public array $dirs = [];

    /** @var array<string, int> path => modification time (unix seconds), set on every write */
    public array $modified = [];

    /** Whether listings carry `modified` (false: a node that reports none). */
    public bool $reportsModified = true;

    /** @var array<string, string> URL => body the "node" downloads */
    public array $remote = [];

    /** @var array<int, array{string, array<int, mixed>}> */
    public array $calls = [];

    /** @var array<int, string> */
    public array $violations = [];

    /** Runs after a pull lands, e.g. to simulate the worker dying. */
    public ?\Closure $afterPull = null;

    /** Runs before a delete, e.g. to make it fail. */
    public ?\Closure $beforeDelete = null;

    /** @var array<int, string> paths the "node" refuses to move (simulated failures) */
    public array $failMoves = [];

    /** Runs after a preserved move landed, e.g. to simulate the worker dying before it is recorded. */
    public ?\Closure $afterMove = null;

    /** Runs before a stat, e.g. to make the node fail before anything moved. */
    public ?\Closure $beforeStat = null;

    /** Runs before a write lands, e.g. to make it time out. */
    public ?\Closure $beforeWrite = null;

    /** Runs after a write landed, e.g. to tamper with the file. */
    public ?\Closure $afterWrite = null;

    /** Runs before a listing, e.g. to simulate the worker dying mid-way through a scan. */
    public ?\Closure $beforeList = null;

    /** Runs before an archive is unpacked, e.g. to simulate the worker dying after the archive write. */
    public ?\Closure $beforeDecompress = null;

    /** Runs after an archive was unpacked, e.g. to simulate the worker dying before that is recorded. */
    public ?\Closure $afterDecompress = null;

    /** @var array<int, string> paths an unpack silently skips (as Wings does for its file denylist) */
    public array $ignored = [];

    /** @var array<int, array<int, string>> the file names each unpack wrote */
    public array $extracted = [];

    /** @var array<int, string> worlds protected by the copy the engine got */
    private array $worlds = ['world'];

    /**
     * Shares state with the original (same filesystem, same call log): only the
     * protected worlds differ, as with the real adapter.
     */
    public function protecting(array $worlds): static
    {
        $copy = clone $this;
        $copy->worlds = array_values(array_unique(array_merge($this->worlds, $worlds)));
        $copy->files = &$this->files;
        $copy->dirs = &$this->dirs;
        $copy->modified = &$this->modified;
        $copy->reportsModified = &$this->reportsModified;
        $copy->calls = &$this->calls;
        $copy->violations = &$this->violations;
        $copy->remote = &$this->remote;
        $copy->failMoves = &$this->failMoves;
        $copy->afterMove = &$this->afterMove;
        $copy->beforeStat = &$this->beforeStat;
        $copy->beforeDecompress = &$this->beforeDecompress;
        $copy->beforeList = &$this->beforeList;
        $copy->beforeWrite = &$this->beforeWrite;
        $copy->afterWrite = &$this->afterWrite;
        $copy->beforeDelete = &$this->beforeDelete;
        $copy->afterDecompress = &$this->afterDecompress;
        $copy->ignored = &$this->ignored;
        $copy->extracted = &$this->extracted;

        return $copy;
    }

    /** A change to a protected path outside .modpacks-plus/ is recorded as a violation. */
    private function checkTarget(string $path, bool $creatingDirectory = false): void
    {
        $this->check($path);

        $own = $path === '.modpacks-plus' || str_starts_with($path, '.modpacks-plus/');
        $validator = new PlanValidator(new Caps(), $this->worlds);
        $protected = $creatingDirectory ? $validator->isWithinProtected($path) : $validator->isProtected($path);
        if (!$own && $protected) {
            $this->violations[] = "protected path changed: $path";

            throw new RuntimeException("fake daemon: protected path $path");
        }
    }

    /** @param  array<string, string>  $files */
    public function seed(array $files): self
    {
        foreach ($files as $path => $contents) {
            if (str_ends_with($path, '/')) {
                $this->mkdirs(rtrim($path, '/'));
            } else {
                $this->put($path, $contents);
            }
        }

        return $this;
    }

    private function check(string $path, bool $allowRoot = false): void
    {
        if ($allowRoot && $path === '') {
            return;
        }

        if (!PlanValidator::isSafeRelativePath($path)) {
            $this->violations[] = "unsafe path: $path";

            throw new RuntimeException("fake daemon: unsafe path $path");
        }
    }

    private function mkdirs(string $path): void
    {
        $current = '';
        foreach (explode('/', $path) as $segment) {
            $current = $current === '' ? $segment : "$current/$segment";
            $this->dirs[$current] = true;
        }
    }

    private function put(string $path, string $contents): void
    {
        $directory = dirname($path);
        if ($directory !== '.') {
            $this->mkdirs($directory);
        }
        $this->files[$path] = $contents;
        $this->modified[$path] = Carbon::now()->getTimestamp();
    }

    /** @return array{size: int, directory: bool, modified?: int} */
    private function fileEntry(string $path): array
    {
        $entry = ['size' => strlen($this->files[$path]), 'directory' => false];
        if ($this->reportsModified && isset($this->modified[$path])) {
            $entry['modified'] = $this->modified[$path];
        }

        return $entry;
    }

    /**
     * Arguments of every call of $operation. For 'rename', each pair of a renameMany() call
     * counts as a rename too (so "never renamed" checks see batched moves).
     *
     * @return array<int, array<int, mixed>>
     */
    public function argsOf(string $operation): array
    {
        $out = [];
        foreach ($this->calls as [$op, $args]) {
            if ($op === $operation) {
                $out[] = $args;
            } elseif ($operation === 'rename' && $op === 'renameMany') {
                foreach ($args[0] as $pair) {
                    $out[] = [$pair['from'], $pair['to']];
                }
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public function callsOf(string $operation): array
    {
        return array_map(fn (array $args) => (string) $args[0], $this->argsOf($operation));
    }

    /** Daemon rename requests: one per rename(), one per renameMany() batch. */
    public function renameRequests(): int
    {
        return count(array_filter($this->calls, fn ($call) => in_array($call[0], ['rename', 'renameMany'], true)));
    }

    /** Calls that change the server. */
    public function writeCalls(): int
    {
        return count(array_filter($this->calls, fn ($call) => in_array($call[0], ['createDirectory', 'rename', 'renameMany', 'movePreserved', 'pull', 'write', 'delete', 'decompress'], true)));
    }

    public function list(Server $server, string $directory): ?array
    {
        $this->calls[] = ['list', [$directory]];
        $this->check($directory, true);

        if ($this->beforeList !== null) {
            ($this->beforeList)($directory);
        }

        if ($directory !== '' && !isset($this->dirs[$directory])) {
            return null;
        }

        $prefix = $directory === '' ? '' : "$directory/";
        $entries = [];
        foreach ($this->files as $path => $contents) {
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));
                if (!str_contains($rest, '/')) {
                    $entries[$rest] = $this->fileEntry($path);
                }
            }
        }
        foreach (array_keys($this->dirs) as $path) {
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));
                if ($rest !== '' && !str_contains($rest, '/')) {
                    $entries[$rest] = ['size' => 4096, 'directory' => true];
                }
            }
        }

        return $entries;
    }

    public function stat(Server $server, string $path): ?array
    {
        $this->check($path);

        if ($this->beforeStat !== null) {
            ($this->beforeStat)($path);
        }

        if (isset($this->files[$path])) {
            return $this->fileEntry($path);
        }

        return isset($this->dirs[$path]) ? ['size' => 4096, 'directory' => true] : null;
    }

    public function createDirectory(Server $server, string $path): void
    {
        $this->calls[] = ['createDirectory', [$path]];
        $this->checkTarget($path, creatingDirectory: true);
        $this->mkdirs($path);
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $this->calls[] = ['rename', [$from, $to]];
        $this->checkTarget($from);
        $this->checkTarget($to);

        $this->move($server, $from, $to);
    }

    /**
     * Like the node: every pair is tried, the ones that can't move (target exists, source
     * missing, listed in failMoves) are skipped, and the request fails if any was. afterMove
     * runs after each pair (a worker killed mid-batch).
     */
    public function renameMany(Server $server, array $pairs): void
    {
        $this->calls[] = ['renameMany', [$pairs]];
        if (count($pairs) > ServerFiles::RENAME_BATCH) {
            $this->violations[] = 'rename batch too large: '.count($pairs);

            throw new RuntimeException('fake daemon: too many renames');
        }
        foreach ($pairs as $pair) {
            $this->checkTarget($pair['from']);
            $this->checkTarget($pair['to']);
        }

        $refused = [];
        foreach ($pairs as $pair) {
            if (in_array($pair['from'], $this->failMoves, true)) {
                $refused[] = $pair['from'];

                continue;
            }
            try {
                $this->move($server, $pair['from'], $pair['to']);
            } catch (RuntimeException) {
                $refused[] = $pair['from'];

                continue;
            }
            if ($this->afterMove !== null) {
                ($this->afterMove)($pair['from'], $pair['to']);
            }
        }

        if ($refused !== []) {
            throw new RuntimeException('fake daemon: could not move '.implode(', ', $refused));
        }
    }

    private function move(Server $server, string $from, string $to): void
    {
        if ($this->stat($server, $to) !== null) {
            throw new RuntimeException("fake daemon: $to exists");
        }

        if (isset($this->files[$from])) {
            $this->put($to, $this->files[$from]);
            $this->modified[$to] = $this->modified[$from] ?? $this->modified[$to];
            unset($this->files[$from]);

            return;
        }

        if (!isset($this->dirs[$from])) {
            throw new RuntimeException("fake daemon: $from missing");
        }

        $this->mkdirs($to);
        foreach ($this->files as $path => $contents) {
            if (str_starts_with($path, "$from/")) {
                $this->put($to.substr($path, strlen($from)), $contents);
                $this->modified[$to.substr($path, strlen($from))] = $this->modified[$path] ?? Carbon::now()->getTimestamp();
                unset($this->files[$path]);
            }
        }
        foreach (array_keys($this->dirs) as $path) {
            if ($path === $from || str_starts_with($path, "$from/")) {
                $this->dirs[$to.substr($path, strlen($from))] = true;
                unset($this->dirs[$path]);
            }
        }
    }

    public function movePreserved(Server $server, string $from, string $to): void
    {
        $this->calls[] = ['movePreserved', [$from, $to]];
        $this->check($from);
        $this->check($to);

        try {
            // The real adapter's guard, not a copy of it.
            DaemonServerFiles::guardPreservedMove($from, $to);
        } catch (UnsafePlanException $exception) {
            $this->violations[] = "preserved move refused: $from -> $to";

            throw $exception;
        }

        if (in_array($from, $this->failMoves, true)) {
            throw new RuntimeException("fake daemon: move of $from failed");
        }

        $this->move($server, $from, $to);

        if ($this->afterMove !== null) {
            ($this->afterMove)($from, $to);
        }
    }

    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void
    {
        $this->calls[] = ['pull', [$url, $directory, $filename]];
        $this->check($directory, true);
        $this->checkTarget($directory === '' ? $filename : "$directory/$filename");

        if (!(new PlanValidator(new Caps()))->isAllowedUrl($url) || parse_url($url, PHP_URL_HOST) !== 'cdn.modrinth.com') {
            $this->violations[] = "non-CDN pull: $url";

            throw new RuntimeException("fake daemon: refused $url");
        }

        if (!array_key_exists($url, $this->remote)) {
            throw new RuntimeException("fake daemon: nothing at $url");
        }

        $path = $directory === '' ? $filename : "$directory/$filename";
        $this->put($path, $this->remote[$url]);

        if ($this->afterPull !== null) {
            ($this->afterPull)($url, $path);
        }
    }

    public function read(Server $server, string $path, int $maxBytes): string
    {
        $this->calls[] = ['read', [$path]];
        $this->check($path);

        if (!isset($this->files[$path])) {
            throw new FileNotFoundException($path);
        }

        if (strlen($this->files[$path]) > $maxBytes) {
            throw new RuntimeException("fake daemon: $path too large");
        }

        return $this->files[$path];
    }

    public function write(Server $server, string $path, string $contents, ?int $timeoutSeconds = null): void
    {
        $this->calls[] = ['write', [$path, $timeoutSeconds]];
        $this->checkTarget($path);

        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($path);
        }

        $this->put($path, $contents);

        if ($this->afterWrite !== null) {
            ($this->afterWrite)($path);
        }
    }

    public function delete(Server $server, string $path): void
    {
        $this->calls[] = ['delete', [$path]];
        $this->checkTarget($path);

        if ($this->beforeDelete !== null) {
            ($this->beforeDelete)($path);
        }

        unset($this->files[$path]);
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, "$path/")) {
                unset($this->files[$file]);
            }
        }
        foreach (array_keys($this->dirs) as $directory) {
            if ($directory === $path || str_starts_with($directory, "$path/")) {
                unset($this->dirs[$directory]);
            }
        }
    }

    /**
     * Unpacks a staged archive like Wings: every entry is written (replacing what is there),
     * parents created, ignored paths skipped. The real adapter's guard runs first; then each
     * entry actually in the zip is checked again here, so a hostile entry is recorded as a
     * violation even if the declared paths lied.
     */
    public function decompress(Server $server, string $archive, array $paths): void
    {
        $this->calls[] = ['decompress', [$archive]];
        $this->check($archive);

        try {
            DaemonServerFiles::guardDecompress($archive, $paths, $this->worlds);
        } catch (UnsafePlanException $exception) {
            $this->violations[] = "decompress refused: $archive";

            throw $exception;
        }

        if ($this->beforeDecompress !== null) {
            ($this->beforeDecompress)($archive);
        }

        if (!isset($this->files[$archive])) {
            throw new RuntimeException("fake daemon: $archive missing");
        }

        $temp = (string) tempnam(sys_get_temp_dir(), 'fake-unpack');
        file_put_contents($temp, $this->files[$archive]);
        $zip = new \ZipArchive();

        try {
            if ($zip->open($temp, \ZipArchive::RDONLY) !== true) {
                throw new RuntimeException("fake daemon: $archive is no zip");
            }

            $written = [];
            $validator = new PlanValidator(new Caps(), $this->worlds);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i, \ZipArchive::FL_ENC_RAW);
                $target = rtrim($name, '/');

                if (!PlanValidator::isSafeRelativePath($target)) {
                    $this->violations[] = "unpacked unsafe path: $name";

                    continue;
                }
                if ($validator->isProtected($target) || PlanValidator::isModrinthMetadata($target)) {
                    $this->violations[] = "unpacked protected path: $name";

                    continue;
                }
                if (!in_array($name, $paths, true)) {
                    $this->violations[] = "unpacked undeclared entry: $name";
                }

                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ($opsys === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) !== 0100000 && !str_ends_with($name, '/')) {
                    $this->violations[] = "unpacked non-regular file: $name";
                }

                if (str_ends_with($name, '/')) {
                    $this->mkdirs($target);

                    continue;
                }
                if (in_array($name, $this->ignored, true)) {
                    continue;
                }
                if (isset($this->dirs[$name])) {
                    throw new RuntimeException("fake daemon: $name is a directory");
                }

                $this->put($name, (string) $zip->getFromIndex($i));
                $written[] = $name;
            }
            $zip->close();
        } finally {
            @unlink($temp);
        }

        $this->extracted[] = $written;

        if ($this->afterDecompress !== null) {
            ($this->afterDecompress)($archive);
        }
    }
}
