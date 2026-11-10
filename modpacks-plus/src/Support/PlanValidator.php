<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\NotEnoughDiskException;
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

    /**
     * CurseForge plans (plan 31): the CurseForge CDN, plus cdn.modrinth.com for files matched
     * on Modrinth by hash. Not configurable either. Only cdn.modrinth.com is ever handed to the
     * node; forgecdn files are always fetched, redirect-checked and hashed by the panel.
     */
    public const CURSEFORGE_HOSTS = [
        'cdn.modrinth.com',
        'edge.forgecdn.net',
        'mediafilez.forgecdn.net',
    ];

    /**
     * FTB plans (plan 32): FTB's own file host, the CurseForge CDN for the CurseForge files FTB
     * packs reference (URLs built by CurseForgePackProvider), and cdn.modrinth.com for files
     * matched on Modrinth by sha1. Only cdn.modrinth.com is ever handed to the node; the rest
     * is fetched, redirect-checked and sha1-checked by the panel.
     */
    public const FTB_HOSTS = [
        'cdn.modrinth.com',
        'dist.modpacks.ch',
        'edge.forgecdn.net',
        'mediafilez.forgecdn.net',
    ];

    /**
     * ATLauncher plans (plan 32): ATLauncher's CDN (mods it hosts and the configs archive), the
     * CurseForge CDN for mods linked there (URLs built by CurseForgePackProvider), and
     * cdn.modrinth.com for files matched on Modrinth by sha1. Only cdn.modrinth.com is ever
     * handed to the node; the rest is fetched, redirect-checked and sha1-checked by the panel.
     */
    public const ATLAUNCHER_HOSTS = [
        'cdn.modrinth.com',
        'download.nodecdn.net',
        'edge.forgecdn.net',
        'mediafilez.forgecdn.net',
    ];

    /**
     * The only place an md5 is ever accepted (PlanFile::VERIFY_ATLAUNCHER_MD5): a file in a
     * container on ATLauncher's own CDN (`/containers/atl/` and partner containers such as
     * `/containers/reforged/`). See isAtlauncherMd5Url().
     */
    public const ATLAUNCHER_MD5_ORIGIN = 'https://download.nodecdn.net/containers/';

    /** Why a pack file is listed for the user to download instead of installed. */
    public const MANUAL_REASONS = ['distribution_disabled', 'datapack', 'unsupported_type', 'too_large', 'curseforge_key_required', 'no_usable_hash', 'host_not_allowed', 'not_on_curseforge', 'no_usable_size'];

    /**
     * Server-pack entries that start a server or install a loader (top-level scripts and jars,
     * the installer's output, bundled Java). Never written: the egg installs the loader (plan 35).
     */
    public const SERVER_PACK_LAUNCHER_FOLDERS = ['libraries', 'versions', '.fabric', '.quilt', 'jre', 'jdk', 'java', 'runtime'];

    public const SERVER_PACK_LAUNCHER_FILES = ['eula.txt', 'user_jvm_args.txt', 'unix_args.txt', 'win_args.txt', 'variables.txt', 'server-setup-config.yaml', 'server-setup-config.yml'];

    /** Top-level files a pack may never write (player data and server identity). */
    public const PROTECTED_FILES = [
        'ops.json',
        'whitelist.json',
        'banned-players.json',
        'banned-ips.json',
        'usercache.json',
        'server.properties',
        // The admin's EULA acceptance (plan 36 review): a pack never replaces it.
        'eula.txt',
    ];

    /** Where previous pack files are moved aside; packs never write here. */
    public const OWN_FOLDER = '.modpacks-plus';

    /** Override folders only a game client uses (never written; skipped whatever their size). */
    public const CLIENT_ONLY_FOLDERS = ['resourcepacks', 'shaderpacks', 'screenshots'];

    /** Top-level override files only a game client uses. */
    public const CLIENT_ONLY_FILES = ['options.txt', 'optionsof.txt', 'optionsshaders.txt'];

    /** Archive prefixes applied to the server, lowest precedence first. */
    public const OVERRIDE_PREFIXES = ['overrides/', 'server-overrides/'];

    /** Modrinth Plus / minecraft-modrinth metadata files, which overrides may not write (any case). */
    public const MODRINTH_METADATA_FILES = ['mods/.modrinth-metadata.json', 'plugins/.modrinth-metadata.json'];

    private const MAX_PATH_LENGTH = 1024;

    private const MAX_URL_LENGTH = 2048;

    /** @var array<string, true> lowercase normalised paths that are never written, moved or deleted */
    private array $protectedPaths = [];

    /** @var array<int, string> exact hosts downloads may come from (HTTPS only) */
    private readonly array $allowedHosts;

    /**
     * @param  array<int, string>  $worlds  world folders relative to the server root: the
     *                                      `level-name` (may be nested, e.g. `saves/world`) and
     *                                      any folder holding `level.dat`
     * @param  array<int, string>  $allowedHosts  the provider's download hosts (hostsFor())
     * @param  ?DiskBudget  $budget  plan 38: the server's disk budget; the whole-pack limit is then
     *                               min(configured cap, budget). Null: the configured cap alone
     *
     * @throws UnsafePlanException `unsafe_level_name` when a world path is empty or would resolve
     *                             outside the server root: the world can't be located, so
     *                             nothing may run (fail closed)
     */
    public function __construct(private readonly Caps $caps, array $worlds = ['world'], array $allowedHosts = self::ALLOWED_HOSTS, private readonly ?DiskBudget $budget = null)
    {
        $this->allowedHosts = array_values($allowedHosts);

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
     * Download hosts for a provider's plans; an unknown provider gets none (nothing downloads).
     *
     * @return array<int, string>
     */
    public static function hostsFor(string $provider): array
    {
        return match ($provider) {
            'modrinth' => self::ALLOWED_HOSTS,
            'curseforge' => self::CURSEFORGE_HOSTS,
            'ftb' => self::FTB_HOSTS,
            'atlauncher' => self::ATLAUNCHER_HOSTS,
            default => [],
        };
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

    /** A server pack's root inside its zip: '' (the zip root) or one plain folder name plus `/`. */
    public static function isServerPackRoot(string $root): bool
    {
        return $root === '' || (str_ends_with($root, '/') && !str_contains(substr($root, 0, -1), '/') && self::isSafeRelativePath(substr($root, 0, -1)));
    }

    /** Whether a server-pack target starts a server or installs a loader (never written). */
    public static function isServerPackLauncherFile(string $path): bool
    {
        $segments = explode('/', strtolower($path));

        // Top-level dotfiles and dot-folders (.env, .htaccess, .git/, …) are never part of what a server needs.
        if (str_starts_with($segments[0], '.')) {
            return true;
        }

        if (count($segments) === 1) {
            return (bool) preg_match('/\.(sh|bat|cmd|ps1|command|jar|exe|py|js|pl|rb|php)$/', $segments[0])
                || in_array($segments[0], self::SERVER_PACK_LAUNCHER_FILES, true);
        }

        return in_array($segments[0], self::SERVER_PACK_LAUNCHER_FOLDERS, true);
    }

    /**
     * A "download manually" entry with every field checked: a printable name, a link only to
     * the CurseForge website (shown, never fetched) and a known reason. Null when unusable.
     *
     * @param  array<mixed>  $entry
     * @return array{name: string, url: ?string, reason: string}|null
     */
    public static function manualFile(array $entry): ?array
    {
        $name = is_string($entry['name'] ?? null) ? UnsafePlanException::clean($entry['name']) : '';
        $url = $entry['url'] ?? null;
        $reason = $entry['reason'] ?? null;

        if ($name === '' || !in_array($reason, self::MANUAL_REASONS, true)) {
            return null;
        }

        return ['name' => $name, 'url' => self::manualUrl($url), 'reason' => $reason];
    }

    /**
     * The link shown with a file listed for manual download: a CurseForge page, or (plan 32
     * step 6) the file itself on ATLauncher's CDN when it is one isAtlauncherMd5Url() accepts.
     * Anything else is dropped (null).
     */
    public static function manualUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        if (preg_match('#^https://www\.curseforge\.com/minecraft/[A-Za-z0-9/_.\-]{1,200}$#', $url) && !str_contains($url, '..')) {
            return $url;
        }

        return self::isAtlauncherMd5Url($url) ? $url : null;
    }

    /** Whether a normalised override target is something only a game client uses (top level only). */
    public static function isClientOnlyOverride(string $path): bool
    {
        $segments = explode('/', strtolower($path));

        return (count($segments) > 1 && in_array($segments[0], self::CLIENT_ONLY_FOLDERS, true))
            || (count($segments) === 1 && in_array($segments[0], self::CLIENT_ONLY_FILES, true));
    }

    /**
     * Whether a normalised path touches a world, a player list, server.properties or
     * `.modpacks-plus/`: it is one of them, lies inside one, or contains one (so moving
     * or replacing it would take a world along, e.g. `saves` when the world is `saves/world`).
     * Compared case-insensitively, which only ever protects more.
     */
    public static function isModrinthMetadata(string $path): bool
    {
        return in_array(strtolower($path), self::MODRINTH_METADATA_FILES, true);
    }

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

    /**
     * Whether a normalised path is a protected path or lies inside one (not merely contains
     * one). Creating a directory that only contains a world can't harm it.
     */
    public function isWithinProtected(string $path): bool
    {
        $path = strtolower($path);

        foreach (array_keys($this->protectedPaths) as $protected) {
            if ($path === $protected || str_starts_with($path, $protected.'/')) {
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
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $this->allowedHosts, true)) {
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
     * `server-overrides/`, or for a server pack the part after its root folder ($root: ''
     * or `<folder>/`). Null for directories and entries that are not applied
     * (client-overrides/, the index, anything outside the root). Hostile names throw.
     *
     * @throws UnsafePlanException
     */
    public function overrideTarget(string $entry, ?string $root = null): ?string
    {
        if ($root !== null && !self::isServerPackRoot($root)) {
            throw new UnsafePlanException('unsafe_path', $root);
        }

        if (str_ends_with($entry, '/')) {
            $this->normalisePath(substr($entry, 0, -1));

            return null;
        }

        $name = $this->normalisePath($entry);

        foreach ($root === null ? self::OVERRIDE_PREFIXES : [$root] as $prefix) {
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

        $root = $plan->overrideRoot();
        if ($root !== null && !self::isServerPackRoot($root)) {
            throw new UnsafePlanException('unsafe_path', $root);
        }

        $files = [];
        $skipped = $plan->skippedClientFiles;
        $skippedPack = $plan->skippedPackFiles;
        $skippedFolder = $plan->skippedClientFolderFiles;
        $seen = [];

        foreach ($plan->files as $file) {
            $path = $this->normalisePath($file->path);

            // Plan 33: only a hostile or broken pack lists the file Modrinth Plus trusts as a
            // download (with a URL and hash of its choosing), client-only or not.
            if (self::isModrinthMetadata($path)) {
                throw new UnsafePlanException('pack_ships_metadata', $path);
            }

            if ($file->side === 'unsupported') {
                $skipped[] = $path;

                continue;
            }

            // Plan 30 fix: resource/shader packs (and the other client-only override targets) listed
            // in files are skipped like client-only overrides, whatever env.server says.
            if (self::isClientOnlyOverride($path)) {
                $skipped[] = $path;
                $skippedFolder[] = $path;

                continue;
            }

            if ($this->isProtected($path)) {
                throw new UnsafePlanException('protected_path', $path);
            }

            // Plan 32 security review, every provider: top-level scripts, jars and dotfiles and the
            // launcher folders are never downloaded (listed in the summary, not fatal).
            if (self::isServerPackLauncherFile($path)) {
                $skippedPack[] = $path;

                continue;
            }

            if (isset($seen[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $seen[strtolower($path)] = true;

            if ($file->size < 0 || $file->size > $this->caps->maxFileBytes) {
                throw new UnsafePlanException('file_too_large', $path);
            }

            $url = $this->firstAllowedUrl($file->urls);
            if ($url === null) {
                throw new UnsafePlanException('no_allowed_url', $path);
            }

            $hashes = $file->verify === PlanFile::VERIFY_ATLAUNCHER_MD5
                ? ['md5' => self::atlauncherMd5($file->hashes, $url, $path)]
                : self::validHashes($file->hashes, $path);

            if ($file->verify === PlanFile::VERIFY_MODRINTH && parse_url($url, PHP_URL_HOST) !== 'cdn.modrinth.com') {
                throw new UnsafePlanException('no_allowed_url', $path);
            }

            if (in_array($file->verify, PlanFile::PANEL_FETCHED, true) && $file->size > $this->caps->maxPanelFetchBytes) {
                throw new UnsafePlanException('panel_fetch_too_large', $path);
            }

            $files[] = $file->with(['path' => $path, 'hashes' => $hashes, 'url' => $url]);
        }

        if (count($files) > $this->caps->maxFiles) {
            throw new UnsafePlanException('too_many_files', (string) count($files));
        }

        $overrides = [];
        $skippedOverrides = $plan->skippedOverrides;
        $skippedClient = $plan->skippedClientOverrides;
        $targets = [];

        foreach ($plan->overrides as $override) {
            $path = $this->normalisePath($override->path);

            if ($this->overrideTarget($override->entry, $root) !== $path) {
                throw new UnsafePlanException('unsafe_path', $override->entry);
            }

            // A server pack's own start scripts, installers and bundled Java: the egg installs the loader.
            if ($root !== null && self::isServerPackLauncherFile($path)) {
                $skippedPack[] = $path;

                continue;
            }

            if (isset($targets[strtolower($path)])) {
                throw new UnsafePlanException('duplicate_path', $path);
            }
            $targets[strtolower($path)] = true;

            // Resource/shader packs, screenshots and client options are never written, so their
            // size doesn't matter (path checks above still applied).
            if (self::isClientOnlyOverride($path)) {
                $skippedClient[] = $path;

                continue;
            }

            if ($override->size < 0 || $override->size > $this->caps->maxFileBytes) {
                throw new UnsafePlanException('file_too_large', $path);
            }

            // Overrides are read into the worker's memory before they are written.
            if ($override->size > $this->caps->maxOverrideBytes) {
                throw new UnsafePlanException('override_too_large', $path);
            }

            // Plan 33: a pack never ships the file Modrinth Plus trusts as "installed"
            // (fake ids, file names or pins); the hand-off writes it from verified hashes.
            if ($this->isProtected($path) || self::isModrinthMetadata($path)) {
                $skippedOverrides[] = $path;

                continue;
            }

            $overrides[] = new OverrideEntry($override->entry, $path, $override->size);
        }

        $manual = [];
        foreach ($plan->manualFiles as $entry) {
            $entry = self::manualFile($entry);
            if ($entry !== null) {
                $manual[] = $entry;
            }
        }
        if (count($files) + count($manual) > $this->caps->maxFiles) {
            throw new UnsafePlanException('too_many_files', (string) (count($files) + count($manual)));
        }

        $validated = $plan->with([
            'files' => $files,
            'overrides' => $overrides,
            'skippedClientFiles' => array_values(array_unique($skipped)),
            'skippedOverrides' => array_values(array_unique($skippedOverrides)),
            'skippedClientOverrides' => array_values(array_unique($skippedClient)),
            'skippedPackFiles' => array_values(array_unique($skippedPack)),
            'skippedClientFolderFiles' => array_values(array_unique($skippedFolder)),
            'manualFiles' => $manual,
        ]);

        if ($validated->totalBytes() > $this->caps->maxTotalBytes) {
            throw new UnsafePlanException('total_too_large', (string) $validated->totalBytes());
        }

        // Plan 38: on a disk-limited server the pack must also fit the free space minus headroom.
        if ($this->budget !== null && !$this->budget->fitsDisk($validated->totalBytes())) {
            throw new NotEnoughDiskException($this->budget->detail($validated->totalBytes()), $validated->totalBytes());
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
     * The md5 of an ATLauncher-CDN file that has no sha1/sha512 (PlanFile::VERIFY_ATLAUNCHER_MD5).
     * Refused (`bad_hash`) unless the URL is a file in a container on ATLauncher's own CDN
     * (isAtlauncherMd5Url), the md5 is well-formed and no stronger hash exists (then that one
     * must be used instead).
     *
     * @param  array<string, string>  $hashes
     *
     * @throws UnsafePlanException
     */
    public static function atlauncherMd5(array $hashes, ?string $url, string $detail = ''): string
    {
        $md5 = strtolower($hashes['md5'] ?? '');
        if (!self::isAtlauncherMd5Url($url) || isset($hashes['sha1']) || isset($hashes['sha512'])
            || !preg_match('/^[0-9a-f]{32}$/', $md5)) {
            throw new UnsafePlanException('bad_hash', $detail);
        }

        return $md5;
    }

    /**
     * Whether $url is a file ATLauncher serves from a container on its own CDN, the only URLs an
     * md5 is accepted for (plan 32 rulings, step 6):
     * `https://download.nodecdn.net/containers/<container>/<path>`, exactly that origin (no
     * userinfo, port, other scheme or look-alike host), no query or fragment, a plain container
     * name and a path whose segments, decoded once, are plain names (no empty, `.` or `..`
     * segment, no encoded `/` or `\`, no control characters, no double encoding).
     *
     * Why md5 is enough here: Configs.json, which names the URL and the md5, comes from
     * ATLauncher's own `/containers/atl/` over HTTPS. Swapping the hosted file alone needs an
     * md5 second preimage, which is infeasible; a collision needs whoever writes the manifest's
     * md5, who could name any file anyway. Fetched by the panel only, with no redirects.
     */
    public static function isAtlauncherMd5Url(?string $url): bool
    {
        if ($url === null || strlen($url) > self::MAX_URL_LENGTH || str_contains($url, '?') || str_contains($url, '#')
            || !(new self(new Caps(), ['world'], ['download.nodecdn.net']))->isAllowedUrl($url)
            || !preg_match('#^'.preg_quote(self::ATLAUNCHER_MD5_ORIGIN, '#').'[A-Za-z0-9][A-Za-z0-9._\-]{0,63}/(.+)$#D', $url, $match)) {
            return false;
        }

        $segments = [];
        foreach (explode('/', $match[1]) as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || str_contains($decoded, '/')) {
                return false;
            }
            $segments[] = $decoded;
        }

        // Each decoded segment: no `.`/`..`, control characters, backslashes or leftover percent-encoding.
        return self::isSafeRelativePath(implode('/', $segments));
    }

    /**
     * The digest a downloaded file is checked against: ATLauncher's md5 only for a
     * VERIFY_ATLAUNCHER_MD5 file on ATLauncher's CDN, else the strongest of sha512 / sha1.
     *
     * @return array{string, string} [algorithm, digest]
     *
     * @throws UnsafePlanException
     */
    public static function fileHash(PlanFile $file): array
    {
        if ($file->verify === PlanFile::VERIFY_ATLAUNCHER_MD5) {
            return ['md5', self::atlauncherMd5($file->hashes, $file->url, $file->path)];
        }

        return self::preferredHash($file->hashes, $file->path);
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
        [$algorithm, $expected] = self::fileHash($file);

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
