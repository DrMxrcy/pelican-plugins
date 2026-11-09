<?php

namespace DrMxrcy\ModpacksPlus\Contracts;

use App\Models\Server;

/**
 * The file operations the install engine performs on a server, all relative to the
 * server root. Implementations refuse any path that PlanValidator::isSafeRelativePath()
 * rejects, so a bug upstream still cannot reach outside the root.
 */
interface ServerFiles
{
    /**
     * Entries of a directory ('' = server root), keyed by name, or null when it doesn't exist.
     *
     * @return array<string, array{size: int, directory: bool}>|null
     */
    public function list(Server $server, string $directory): ?array;

    /** @return array{size: int, directory: bool}|null */
    public function stat(Server $server, string $path): ?array;

    /** Creates a directory and its parents; existing ones are fine. */
    public function createDirectory(Server $server, string $path): void;

    /** Moves $from to $to (both relative to the root). Never replaces an existing target. */
    public function rename(Server $server, string $from, string $to): void;

    /** Has the node download $url into $directory/$filename and waits for it (foreground). */
    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void;

    /** Reads a file, refusing more than $maxBytes. Throws FileNotFoundException when missing. */
    public function read(Server $server, string $path, int $maxBytes): string;

    public function write(Server $server, string $path, string $contents): void;

    public function delete(Server $server, string $path): void;
}
