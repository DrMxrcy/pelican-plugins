<?php

namespace DrMxrcy\ModpacksPlus\Contracts;

use App\Models\Server;

/**
 * The file operations the install engine performs on a server, all relative to the
 * server root. Implementations refuse, right before each call reaches the node:
 * - any path PlanValidator::isSafeRelativePath() rejects (outside the root);
 * - creating, writing, deleting or moving (from or to) a protected path (worlds,
 *   player lists, server.properties), except inside this plugin's `.modpacks-plus/`;
 * - pulling anything but an allowlisted cdn.modrinth.com URL (the node follows
 *   redirects on its own, so it only gets URLs Modrinth vouched for).
 * The one way a protected path moves at all is movePreserved(): into this plugin's own
 * folder and back, never replacing anything (loader switches and restores).
 * The one archive the node ever unpacks is a panel-built override archive (decompress()).
 */
interface ServerFiles
{
    /**
     * A copy that also protects these world folders (the install's level-name etc.).
     *
     * @param  array<int, string>  $worlds
     */
    public function protecting(array $worlds): static;

    /**
     * Entries of a directory ('' = server root), keyed by name, or null when it doesn't exist.
     * `modified` (unix seconds) only when the node reported a readable modification time.
     *
     * @return array<string, array{size: int, directory: bool, modified?: int}>|null
     */
    public function list(Server $server, string $directory): ?array;

    /** @return array{size: int, directory: bool, modified?: int}|null */
    public function stat(Server $server, string $path): ?array;

    /** Creates a directory and its parents; existing ones are fine. */
    public function createDirectory(Server $server, string $path): void;

    /** Moves $from to $to (both relative to the root). Never replaces an existing target. */
    public function rename(Server $server, string $from, string $to): void;

    /**
     * Moves a path between the server root and the plugin's own `.modpacks-plus/<folder>/…`
     * (either direction), protected or not. Exactly one side must be inside the own folder,
     * and the target must not exist (nothing is ever replaced).
     */
    public function movePreserved(Server $server, string $from, string $to): void;

    /** Has the node download a cdn.modrinth.com $url into $directory/$filename and waits for it (foreground). */
    public function pull(Server $server, string $url, string $directory, string $filename, int $timeoutSeconds): void;

    /** Reads a file, refusing more than $maxBytes. Throws FileNotFoundException when missing. */
    public function read(Server $server, string $path, int $maxBytes): string;

    public function write(Server $server, string $path, string $contents): void;

    public function delete(Server $server, string $path): void;

    /**
     * Has the node unpack a staged override archive (plan 42) into the server root and waits
     * for it. Only `.modpacks-plus/staging/overrides-<install>-<chunk>-<attempt>.zip`
     * (OverrideArchive::isStagingPath()) is accepted, and $paths, the entries the panel put
     * in it (OverrideArchive::$paths), are each refused like a write would be, and further:
     * nothing inside `.modpacks-plus/`, no Modrinth metadata file, no duplicates.
     *
     * @param  array<int, string>  $paths
     */
    public function decompress(Server $server, string $archive, array $paths): void;
}
