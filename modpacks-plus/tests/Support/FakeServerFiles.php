<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
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
        $copy->calls = &$this->calls;
        $copy->violations = &$this->violations;
        $copy->remote = &$this->remote;
        $copy->failMoves = &$this->failMoves;
        $copy->afterMove = &$this->afterMove;
        $copy->beforeStat = &$this->beforeStat;

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
    }

    /** @return array<int, array<int, mixed>> arguments of every call of $operation */
    public function argsOf(string $operation): array
    {
        $out = [];
        foreach ($this->calls as [$op, $args]) {
            if ($op === $operation) {
                $out[] = $args;
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public function callsOf(string $operation): array
    {
        $out = [];
        foreach ($this->calls as [$op, $args]) {
            if ($op === $operation) {
                $out[] = (string) $args[0];
            }
        }

        return $out;
    }

    /** Calls that change the server. */
    public function writeCalls(): int
    {
        return count(array_filter($this->calls, fn ($call) => in_array($call[0], ['createDirectory', 'rename', 'movePreserved', 'pull', 'write', 'delete'], true)));
    }

    public function list(Server $server, string $directory): ?array
    {
        $this->calls[] = ['list', [$directory]];
        $this->check($directory, true);

        if ($directory !== '' && !isset($this->dirs[$directory])) {
            return null;
        }

        $prefix = $directory === '' ? '' : "$directory/";
        $entries = [];
        foreach ($this->files as $path => $contents) {
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));
                if (!str_contains($rest, '/')) {
                    $entries[$rest] = ['size' => strlen($contents), 'directory' => false];
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
            return ['size' => strlen($this->files[$path]), 'directory' => false];
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

    private function move(Server $server, string $from, string $to): void
    {
        if ($this->stat($server, $to) !== null) {
            throw new RuntimeException("fake daemon: $to exists");
        }

        if (isset($this->files[$from])) {
            $this->put($to, $this->files[$from]);
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

    public function write(Server $server, string $path, string $contents): void
    {
        $this->calls[] = ['write', [$path]];
        $this->checkTarget($path);
        $this->put($path, $contents);
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
}
