<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
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
        return count(array_filter($this->calls, fn ($call) => in_array($call[0], ['createDirectory', 'rename', 'pull', 'write', 'delete'], true)));
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

        if (isset($this->files[$path])) {
            return ['size' => strlen($this->files[$path]), 'directory' => false];
        }

        return isset($this->dirs[$path]) ? ['size' => 4096, 'directory' => true] : null;
    }

    public function createDirectory(Server $server, string $path): void
    {
        $this->calls[] = ['createDirectory', [$path]];
        $this->check($path);
        $this->mkdirs($path);
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $this->calls[] = ['rename', [$from, $to]];
        $this->check($from);
        $this->check($to);

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

    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void
    {
        $this->calls[] = ['pull', [$url, $directory, $filename]];
        $this->check($directory, true);
        $this->check($filename);

        if (!(new PlanValidator(new Caps()))->isAllowedUrl($url)) {
            $this->violations[] = "non-allowlisted pull: $url";

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
        $this->check($path);
        $this->put($path, $contents);
    }

    public function delete(Server $server, string $path): void
    {
        $this->calls[] = ['delete', [$path]];
        $this->check($path);
        unset($this->files[$path]);
    }
}
