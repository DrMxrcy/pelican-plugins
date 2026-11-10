<?php

namespace DrMxrcy\ModpacksPlus\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;

/**
 * Plan 43: what a loader egg's install script leaves behind when it worked, so a loader
 * switch can tell a reinstall the node reported successful (live 2026-11-10: a script that
 * printed a message and exited 1 still counted as installed) from one that left nothing to
 * start. At most two directory listings (the root and `libraries/net`), no file contents.
 *
 * From the official pelican-eggs/minecraft scripts (checked 2026-11-10):
 * - NeoForge: the installer's `run.sh` and `libraries/net/neoforged/...`, the script links
 *   `unix_args.txt` into the root.
 * - Forge: the same for 1.17+ (`libraries/net/minecraftforge/...`); older versions get the
 *   forge jar moved to SERVER_JARFILE instead.
 * - Fabric / Quilt: `fabric-server-launch.jar` / `quilt-server-launch.jar`, which the
 *   official scripts rename to `server.jar` (the startup runs SERVER_JARFILE).
 *
 * A loader this map does not know is not checked.
 */
final class LoaderLaunchFiles
{
    /** Placeholder for the egg's SERVER_JARFILE value in the map below. */
    private const JAR = '{SERVER_JARFILE}';

    /**
     * Per loader: alternatives, any one of which is enough. Each alternative needs one of
     * its `root` names in the server root and (when set) `libraries/net/<library>`.
     *
     * @var array<string, array<int, array{root: array<int, string>, library: ?string}>>
     */
    private const MAP = [
        'neoforge' => [
            ['root' => ['unix_args.txt', 'run.sh'], 'library' => 'neoforged'],
        ],
        'forge' => [
            ['root' => ['unix_args.txt', 'run.sh'], 'library' => 'minecraftforge'],
            ['root' => [self::JAR], 'library' => null],
        ],
        'fabric' => [
            ['root' => ['fabric-server-launch.jar', self::JAR], 'library' => null],
        ],
        'quilt' => [
            ['root' => ['quilt-server-launch.jar', self::JAR], 'library' => null],
        ],
    ];

    public static function knows(string $loader): bool
    {
        return isset(self::MAP[$loader]);
    }

    /**
     * What is missing for $loader to start, or null when its launch files are there (or the
     * loader is not one this map knows). Daemon errors propagate: the caller retries.
     *
     * @param  ?string  $jarFile  the server's SERVER_JARFILE value, if its egg has one
     */
    public static function missing(ServerFiles $files, Server $server, string $loader, ?string $jarFile): ?string
    {
        if (!self::knows($loader)) {
            return null;
        }

        $jarFile = self::jarFile($jarFile);
        $alternatives = [];
        foreach (self::MAP[$loader] as $alternative) {
            $names = array_values(array_filter(
                array_map(fn (string $name) => $name === self::JAR ? $jarFile : $name, $alternative['root']),
                fn (?string $name) => $name !== null,
            ));
            if ($names === []) {
                continue;
            }
            $alternatives[] = ['root' => array_values(array_unique($names)), 'library' => $alternative['library']];
        }

        $root = $files->list($server, '') ?? [];
        $libraries = null;
        $missingParts = [];

        foreach ($alternatives as $alternative) {
            $missing = [];
            if (!self::anyFile($root, $alternative['root'])) {
                $missing[] = implode(' or ', $alternative['root']);
            }
            if ($alternative['library'] !== null) {
                $libraries ??= $files->list($server, 'libraries/net') ?? [];
                if (!isset($libraries[$alternative['library']])) {
                    $missing[] = 'libraries/net/'.$alternative['library'];
                }
            }

            if ($missing === []) {
                return null;
            }
            $missingParts[] = implode(' + ', $missing);
        }

        // Every loader has an alternative without the jar placeholder, so this is never empty.
        return implode('; or ', $missingParts);
    }

    /**
     * @param  array<string, array{size: int, directory: bool}>  $root
     * @param  array<int, string>  $names
     */
    private static function anyFile(array $root, array $names): bool
    {
        foreach ($names as $name) {
            if (isset($root[$name]) && !$root[$name]['directory']) {
                return true;
            }
        }

        return false;
    }

    /** A plain file name in the root, as the egg's startup runs it; anything else is ignored. */
    private static function jarFile(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[\w.\-]{1,128}$/', $value) === 1 && $value !== '.' && $value !== '..' ? $value : null;
    }
}
