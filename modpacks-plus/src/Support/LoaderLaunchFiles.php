<?php

namespace DrMxrcy\ModpacksPlus\Support;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;

/**
 * Plan 43: what a loader egg's install script leaves behind when it worked, so a loader
 * switch can tell a reinstall the node reported successful (live 2026-11-10: a script that
 * printed a message and exited 1 still counted as installed) from one that left nothing to
 * start. At most two directory listings (the root and `libraries/net`), no file contents;
 * a launch file left by the previous loader (older than the reinstall) does not count.
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
    /** Seconds a launch file may predate the reinstall request (node and panel clocks differ). */
    public const CLOCK_SKEW = 120;

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
     * What is missing for $loader to start (null: its launch files are there, or the loader
     * is not one this map knows), and whether a matched launch file had no modification time.
     * With $since, a launch file counts only when it was written at or after $since minus
     * CLOCK_SKEW: the official scripts rewrite these files on every install, so a file older
     * than the reinstall was left by the previous loader (live 2026-11-10: a NeoForge to
     * Fabric switch whose script exited 1 left the old server.jar). A file without a time
     * counts (`undated`): unknown is not proof of a failed install. Daemon errors propagate:
     * the caller retries.
     *
     * @param  ?string  $jarFile  the server's SERVER_JARFILE value, if its egg has one
     * @param  ?int  $since  when the reinstall was requested (unix seconds)
     * @return array{missing: ?string, undated: bool}
     */
    public static function check(ServerFiles $files, Server $server, string $loader, ?string $jarFile, ?int $since = null): array
    {
        if (!self::knows($loader)) {
            return ['missing' => null, 'undated' => false];
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
            $undated = false;
            $present = self::presentFiles($root, $alternative['root']);
            if ($present === []) {
                $missing[] = implode(' or ', $alternative['root']);
            } elseif ($since !== null) {
                $fresh = false;
                foreach ($present as $name) {
                    $modified = $root[$name]['modified'] ?? null;
                    if ($modified === null) {
                        $undated = true;
                    } elseif ($modified >= $since - self::CLOCK_SKEW) {
                        $fresh = true;
                    }
                }
                if ($fresh) {
                    $undated = false;
                } elseif (!$undated) {
                    $missing[] = implode(' or ', $alternative['root']).' (only older than the reinstall: '.implode(', ', $present).')';
                }
            }
            if ($alternative['library'] !== null) {
                $libraries ??= $files->list($server, 'libraries/net') ?? [];
                if (!isset($libraries[$alternative['library']])) {
                    $missing[] = 'libraries/net/'.$alternative['library'];
                }
            }

            if ($missing === []) {
                return ['missing' => null, 'undated' => $undated];
            }
            $missingParts[] = implode(' + ', $missing);
        }

        // Every loader has an alternative without the jar placeholder, so this is never empty.
        return ['missing' => implode('; or ', $missingParts), 'undated' => false];
    }

    /** check() without the time check: what is missing, or null. */
    public static function missing(ServerFiles $files, Server $server, string $loader, ?string $jarFile): ?string
    {
        return self::check($files, $server, $loader, $jarFile)['missing'];
    }

    /**
     * The names among $names that are files in the root.
     *
     * @param  array<string, array{size: int, directory: bool, modified?: int}>  $root
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private static function presentFiles(array $root, array $names): array
    {
        return array_values(array_filter($names, fn (string $name) => isset($root[$name]) && !$root[$name]['directory']));
    }

    /** A plain file name in the root, as the egg's startup runs it; anything else is ignored. */
    private static function jarFile(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[\w.\-]{1,128}$/', $value) === 1 && $value !== '.' && $value !== '..' ? $value : null;
    }
}
