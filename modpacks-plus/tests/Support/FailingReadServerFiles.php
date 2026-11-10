<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use RuntimeException;

/**
 * Wraps a FakeServerFiles and makes the daemon fail: reads of one path (as a timeout or
 * daemon error would, not "file not found"), and optionally every write.
 */
final class FailingReadServerFiles implements ServerFiles
{
    public function __construct(
        public readonly FakeServerFiles $inner,
        private readonly ?string $failReadOf = null,
        private readonly bool $failWrites = false,
    ) {}

    public function protecting(array $worlds): static
    {
        return new self($this->inner->protecting($worlds), $this->failReadOf, $this->failWrites);
    }

    public function list(Server $server, string $directory): ?array
    {
        return $this->inner->list($server, $directory);
    }

    public function stat(Server $server, string $path): ?array
    {
        return $this->inner->stat($server, $path);
    }

    public function createDirectory(Server $server, string $path): void
    {
        $this->inner->createDirectory($server, $path);
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $this->inner->rename($server, $from, $to);
    }

    public function renameMany(Server $server, array $pairs): void
    {
        $this->inner->renameMany($server, $pairs);
    }

    public function movePreserved(Server $server, string $from, string $to): void
    {
        $this->inner->movePreserved($server, $from, $to);
    }

    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void
    {
        $this->inner->pull($server, $url, $directory, $filename, $timeoutSeconds);
    }

    public function read(Server $server, string $path, int $maxBytes): string
    {
        if ($path === $this->failReadOf) {
            $this->inner->calls[] = ['read', [$path]];

            throw new RuntimeException("fake daemon: timed out reading $path");
        }

        return $this->inner->read($server, $path, $maxBytes);
    }

    public function write(Server $server, string $path, string $contents, ?int $timeoutSeconds = null): void
    {
        if ($this->failWrites) {
            $this->inner->calls[] = ['write', [$path]];

            throw new RuntimeException("fake daemon: could not write $path");
        }

        $this->inner->write($server, $path, $contents);
    }

    public function delete(Server $server, string $path): void
    {
        $this->inner->delete($server, $path);
    }

    public function decompress(Server $server, string $archive, array $paths): void
    {
        $this->inner->decompress($server, $archive, $paths);
    }
}
