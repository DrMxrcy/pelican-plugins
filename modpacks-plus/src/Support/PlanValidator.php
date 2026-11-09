<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;

/**
 * The safety rules every pack must pass before anything is written to a server.
 * Pure: no I/O, no config, no container. Callers pass the caps and the server's
 * world folder names.
 *
 * Threats handled here:
 * - path traversal / zip-slip: a pack-supplied path or archive entry that would
 *   land outside the server root (`..`, absolute, drive letter, backslash, encoded);
 * - SSRF through the node: a download URL that is not HTTPS on the allowlist;
 * - tampered downloads: missing or mismatching sha512/sha1;
 * - resource exhaustion: caps on file count, sizes and archive entries;
 * - data loss: files aimed at worlds, ops/whitelist/ban lists, server.properties
 *   or this plugin's own `.modpacks-plus/` folder.
 */
final class PlanValidator
{
    /** Exact hosts the node may download from (HTTPS only). Deliberately not configurable. */
    public const ALLOWED_HOSTS = [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'objects.githubusercontent.com',
        'gitlab.com',
    ];

    /** Top-level files a pack may never write (player data and server identity). */
    public const PROTECTED_FILES = [
        'ops.json',
        'whitelist.json',
        'banned-players.json',
        'banned-ips.json',
        'usercache.json',
        'server.properties',
    ];

    /** Where previous pack files are moved aside; packs never write here. */
    public const OWN_FOLDER = '.modpacks-plus';

    /** Archive prefixes applied to the server, lowest precedence first. */
    public const OVERRIDE_PREFIXES = ['overrides/', 'server-overrides/'];

    private const MAX_PATH_LENGTH = 1024;

    private const MAX_URL_LENGTH = 2048;

    /** @var array<string, true> lowercase normalised paths that are never written, moved or deleted */
    private array $protectedPaths = [];

    /**
     * @param  array<int, string>  $worlds  world folders relative to the server root: the
     *                                      `level-name` (may be nested, e.g. `saves/world`) and
     *                                      any folder holding `level.dat`
     *
     * @throws UnsafePlanException `unsafe_level_name` when a world path is empty or would resolve
     *                             outside the server root: the world can't be located, so
     *                             nothing may run (fail closed)
     */
    public function __construct(private readonly Caps $caps, array $worlds = ['world'])
    {
        foreach (self::PROTECTED_FILES as $file) {
            $this->protectedPaths[$file] = true;
        }
        $this->protectedPaths[self::OWN_FOLDER] = true;

        foreach (array_merge(['world'], $worlds) as $world) {
            try {
                $world = $this->normalisePath($world);
            } catch (UnsafePlanException) {
                throw new UnsafePlanException('unsafe_level_name', $world);
            }

            // Vanilla/Bukkit keep the other dimensions next to the world folder.
            foreach (['', '_nether', '_the_end'] as $suffix) {
                $this->protectedPaths[strtolower($world.$suffix)] = true;
            }
        }
    }

    /**
     * Returns the path unchanged when it is a plain relative path under the server
     * root, else throws `unsafe_path`. Strict on purpose: anything a filesystem, URL
     * decoder or Windows could read as a separator or a parent reference is refused.
     *
     * @throws UnsafePlanException
     */
    public function normalisePath(string $path): string
    {
        if (!self::isSafeRelativePath($path)) {
            throw new UnsafePlanException('unsafe_path', $path);
        }

        return $path;
    }

    /** The path rules of normalisePath() as a static check (used again right before each daemon call). */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || !mb_check_encoding($path, 'UTF-8')) {
            return false;
        }

        // Control characters (incl. NUL), backslashes and percent-encoding.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) || preg_match('/%[0-9A-Fa-f]{2}/', $path)) {
            return false;
        }

        // Absolute paths and drive letters (C:, c:foo).
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        // Belt and braces: the logical resolution must stay under the root.
        return self::resolvesUnderRoot($path);
    }

    /** Resolves `.` and `..` logically against a root and reports whether the result stays strictly inside it. */
    public static function resolvesUnderRoot(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack === []) {
                    return false;
                }
                array_pop($stack);

                continue;
            }
            $stack[] = $segment;
        }

        return $stack !== [];
    }

    /**
     * Whether a normalised path touches a world, a player list, server.properties or
     * `.modpacks-plus/`: it is one of them, lies inside one, or contains one (so moving
     * or replacing it would take a world along, e.g. `saves` when the world is `saves/world`).
     * Compared case-insensitively, which only ever protects more.
     */
    public function isProtected(string $path): bool
    {
        $path = strtolower($path);

        foreach (array_keys($this->protectedPaths) as $protected) {
            if ($path === $protected || str_starts_with($path, $protected.'/') || str_starts_with($protected, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    public function isAllowedUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        // Printable ASCII URL characters only: no whitespace, backslash, `@` (userinfo) or non-ASCII.
        if (!preg_match('~^[A-Za-z0-9\-._\~:/?#\[\]!$&\'()*+,;=%]+$~', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        // Whatever a parser makes of it, the URL must literally start with the allowed origin.
        return str_starts_with($url, "https://$host/");
    }

    /** @param  array<int, string>  $urls */
    public function firstAllowedUrl(array $urls): ?string
    {
        foreach ($urls as $url) {
            if ($this->isAllowedUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Checks every entry name of a pack archive (zip-slip) and the entry count.
     *
     * @param  array<int, string>  $names
     *
     * @throws UnsafePlanException
     */
    public function checkArchiveEntries(array $names): void
    {
        if (count($names) > $this->caps->maxArchiveEntries) {
            throw new UnsafePlanException('too_many_entries', (string) count($names));
        }

        foreach ($names as $name) {
            $this->normalisePath(str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
        }
    }

    /**
     * The server path an archive entry is written to: the part after `overrides/` or
     * `server-overrides/`. Null for directories and entries that are not applied
     * (client-overrides/, the index). Hostile names throw.
     *
     * @throws UnsafePlanException
     */
    public function overrideTarget(string $entry): ?string
    {
        if (str_ends_with($entry, '/')) {
            $this->normalisePath(substr($entry, 0, -1));

            return null;
        }

        $name = $this->normalisePath($entry);

        foreach (self::OVERRIDE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $target = substr($name, strlen($prefix));

                return $target === '' ? null : $this->normalisePath($target);
            }
        }

        return null;
    }

    /**
     * Validates a whole plan and returns it ready to run: client-only files dropped,
     * a download URL picked per file, overrides aimed at protected paths dropped.
     * Idempotent: validating the result again yields the same plan.
     *
     * @throws UnsafePlanException
     */
    public function validate(InstallPlan $plan): InstallPlan
    {
        foreach (array_filter([$plan->archive, $plan->serverPack]) as $archive) {
            $this->validateArchive($archive);
        }

        $files = [];
        $skipped = $plan->skippedClientFiles;
        $seen = [];

        foreach ($plan->files as $file) {
            $path = $this->normalisePath($file->path);

            if ($file->side === 'unsupported') {
                $skipped[] = $path;

                continue;
            }

            if ($this->isProtected($path)) {
                throw new UnsafePlanException('protected_path', $path);
            }

            if (isset($seen[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $seen[strtolower($path)] = true;

            if ($file->size < 0 || $file->size > $this->caps->maxFileBytes) {
                throw new UnsafePlanException('file_too_large', $path);
            }

            $hashes = self::validHashes($file->hashes, $path);

            $url = $this->firstAllowedUrl($file->urls);
            if ($url === null) {
                throw new UnsafePlanException('no_allowed_url', $path);
            }

            if ($file->verify === PlanFile::VERIFY_READBACK && $file->size > $this->caps->maxReadbackBytes) {
                throw new UnsafePlanException('readback_too_large', $path);
            }

            $files[] = $file->with(['path' => $path, 'hashes' => $hashes, 'url' => $url]);
        }

        if (count($files) > $this->caps->maxFiles) {
            throw new UnsafePlanException('too_many_files', (string) count($files));
        }

        $overrides = [];
        $skippedOverrides = $plan->skippedOverrides;
        $targets = [];

        foreach ($plan->overrides as $override) {
            $path = $this->normalisePath($override->path);

            if ($this->overrideTarget($override->entry) !== $path) {
                throw new UnsafePlanException('unsafe_path', $override->entry);
            }

            if ($override->size < 0 || $override->size > $this->caps->maxFileBytes) {
                throw new UnsafePlanException('file_too_large', $path);
            }

            if (isset($targets[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $targets[strtolower($path)] = true;

            if ($this->isProtected($path)) {
                $skippedOverrides[] = $path;

                continue;
            }

            $overrides[] = new OverrideEntry($override->entry, $path, $override->size);
        }

        $validated = $plan->with([
            'files' => $files,
            'overrides' => $overrides,
            'skippedClientFiles' => array_values(array_unique($skipped)),
            'skippedOverrides' => array_values(array_unique($skippedOverrides)),
        ]);

        if ($validated->totalBytes() > $this->caps->maxTotalBytes) {
            throw new UnsafePlanException('total_too_large', (string) $validated->totalBytes());
        }

        return $validated;
    }

    /** @throws UnsafePlanException */
    private function validateArchive(PackArchive $archive): void
    {
        if (!$this->isAllowedUrl($archive->url)) {
            throw new UnsafePlanException('no_allowed_url', $archive->url);
        }

        if ($archive->size < 0 || $archive->size > $this->caps->maxArchiveBytes) {
            throw new UnsafePlanException('archive_too_large', $archive->filename);
        }

        self::validHashes($archive->hashes, $archive->filename);
    }

    /**
     * Lowercased sha512/sha1 digests; at least one must be present and every one that
     * is present must be well-formed (a malformed sha512 never falls back to sha1).
     *
     * @param  array<string, string>  $hashes
     * @return array<string, string>
     *
     * @throws UnsafePlanException
     */
    public static function validHashes(array $hashes, string $detail = ''): array
    {
        $valid = [];
        foreach (['sha512' => 128, 'sha1' => 40] as $algorithm => $length) {
            if (!array_key_exists($algorithm, $hashes)) {
                continue;
            }

            $digest = strtolower($hashes[$algorithm]);
            if (!preg_match('/^[0-9a-f]{'.$length.'}$/', $digest)) {
                throw new UnsafePlanException('bad_hash', $detail);
            }
            $valid[$algorithm] = $digest;
        }

        if ($valid === []) {
            throw new UnsafePlanException('bad_hash', $detail);
        }

        return $valid;
    }

    /**
     * The strongest known digest: sha512, else sha1.
     *
     * @param  array<string, string>  $hashes
     * @return array{string, string} [algorithm, digest]
     *
     * @throws UnsafePlanException
     */
    public static function preferredHash(array $hashes, string $detail = ''): array
    {
        $valid = self::validHashes($hashes, $detail);

        return isset($valid['sha512']) ? ['sha512', $valid['sha512']] : ['sha1', $valid['sha1']];
    }

    /** @throws UnsafePlanException */
    public static function assertContentMatches(PlanFile $file, string $contents): void
    {
        [$algorithm, $expected] = self::preferredHash($file->hashes, $file->path);

        if (!hash_equals($expected, hash($algorithm, $contents))) {
            throw new UnsafePlanException('hash_mismatch', $file->path);
        }
    }

    /**
     * Compares a digest computed elsewhere (a streamed file) with the strongest expected one.
     * A digest of a weaker algorithm than the strongest known is refused.
     *
     * @param  array<string, string>  $hashes
     *
     * @throws UnsafePlanException
     */
    public static function assertDigestMatches(array $hashes, string $algorithm, string $digest, string $detail = ''): void
    {
        [$expectedAlgorithm, $expected] = self::preferredHash($hashes, $detail);

        if ($algorithm !== $expectedAlgorithm || !hash_equals($expected, strtolower($digest))) {
            throw new UnsafePlanException('hash_mismatch', $detail);
        }
    }
}
