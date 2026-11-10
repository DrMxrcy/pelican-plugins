<?php

namespace DrMxrcy\ModpacksPlus\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;

/**
 * stat() answered from one listing per directory, for deciding a whole batch of renames
 * with a few requests instead of one per path. Only valid until something moves: make a
 * new one per batch.
 */
final class DirectoryListings
{
    /** @var array<string, array<string, array{size: int, directory: bool}>|null> */
    private array $listings = [];

    public function __construct(private readonly ServerFiles $files, private readonly Server $server) {}

    /** @return array{size: int, directory: bool}|null */
    public function stat(string $path): ?array
    {
        $directory = self::parent($path);
        if (!array_key_exists($directory, $this->listings)) {
            $this->listings[$directory] = $this->files->list($this->server, $directory);
        }

        return $this->listings[$directory][basename($path)] ?? null;
    }

    /**
     * Creates the missing parent directories of $paths, one call per deepest missing one.
     *
     * @param  array<int, string>  $paths
     */
    public function createParents(array $paths): void
    {
        $missing = [];
        foreach ($paths as $path) {
            $directory = self::parent($path);
            if ($directory !== '' && !isset($missing[$directory]) && $this->stat($directory) === null) {
                $missing[$directory] = true;
            }
        }

        $directories = array_keys($missing);
        foreach ($directories as $directory) {
            foreach ($directories as $other) {
                if (str_starts_with((string) $other, $directory.'/')) {
                    continue 2;
                }
            }
            $this->files->createDirectory($this->server, (string) $directory);
        }
    }

    /** $path's directory, '' for the server root. */
    public static function parent(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $directory;
    }
}
