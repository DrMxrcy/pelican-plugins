<?php

namespace DrMxrcy\ModpacksPlus\Providers\AtLauncher;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackFileResolver;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JsonApiClient;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Support\Facades\Cache;

/**
 * ATLauncher modpacks (plan 32): public packs from https://api.atlauncher.com/v1, each
 * version's Configs.json (its mods, loader and configs archive) from ATLauncher's CDN
 * (https://download.nodecdn.net/containers/atl), the same files the ATLauncher app reads.
 *
 * - Mods the pack marks `server: false` are left out, as are optional ones; only `mods`
 *   are placed (client-side types are skipped, data packs and other kinds listed).
 * - ATLauncher gives only an md5 for most mods. A mod is verified by a sha1 where one exists:
 *   ATLauncher's own, else CurseForge's for the CurseForge file the mod names (curse_id /
 *   curse_file_id, when a CurseForge key or the mirror is set up); Modrinth-first applies to
 *   those sha1s. With no sha1 anywhere, a mod ATLauncher serves from its own CDN
 *   (`download: server`) is checked against ATLauncher's md5 (PlanFile::VERIFY_ATLAUNCHER_MD5,
 *   parent ruling: ATLauncher writes both the manifest and the file, so its md5 adds no new
 *   trust). md5 is never accepted for any other host; such a mod is listed. See PackFileResolver.
 * - Mods ATLauncher hosts (`download: server`) come from its CDN; `direct` links on the
 *   CurseForge CDN go through plan 31's rules, other hosts are listed; `browser` downloads
 *   are listed.
 * - The configs archive (Configs.zip, sha1 from Configs.json) is read by the panel and its
 *   files written like a server pack's: from the zip root, without top-level scripts, jars
 *   or dotfiles, client-only folders or protected paths.
 *
 * The node never pulls anything here except Modrinth-verified cdn.modrinth.com files.
 *
 * @phpstan-import-type PackHit from PackProvider
 * @phpstan-import-type PackVersion from PackProvider
 */
class AtLauncherPackProvider implements PackProvider
{
    public const ID = 'atlauncher';

    public const API_URL = 'https://api.atlauncher.com/v1';

    public const CDN_URL = 'https://download.nodecdn.net/containers/atl';

    /** Where ATLauncher serves pack files and configs from (panel-fetched only). */
    public const DOWNLOAD_HOSTS = ['download.nodecdn.net'];

    /** Mod types only a game client uses. */
    private const CLIENT_TYPES = ['resourcepack', 'texturepack', 'shaderpack', 'resourcepackextract', 'texturepackextract'];

    private const MAX_PACKS = 1000;

    private const MAX_VERSIONS = 200;

    private const MAX_PAGE_SIZE = 20;

    /** Versions whose loader is looked up (one Configs.json each) for the detail view. */
    private const MAX_LOADER_LOOKUPS = 10;

    private const CACHE_MINUTES = 10;

    private const MAX_DESCRIPTION_CHARS = 5000;

    public function __construct(
        private readonly JsonApiClient $api,
        private readonly JsonApiClient $cdn,
        private readonly PackFileResolver $resolver,
        private readonly ModrinthPackProvider $modrinth,
        private readonly Caps $caps,
    ) {}

    public static function apiClient(): JsonApiClient
    {
        return new JsonApiClient(self::API_URL, 'atlauncher_unavailable', 'atlauncher_not_found');
    }

    public static function cdnClient(): JsonApiClient
    {
        return new JsonApiClient(self::CDN_URL, 'atlauncher_unavailable', 'atlauncher_not_found');
    }

    public function id(): string
    {
        return self::ID;
    }

    /** Pack safe names and version names: one plain path segment (letters, digits, `.`, `_`, `+`, `-`). */
    public static function isValidId(string $id): bool
    {
        return strlen($id) <= 64 && JsonApiClient::isSafeSegment($id);
    }

    public function sorts(): array
    {
        return [];
    }

    public function categories(): array
    {
        return [];
    }

    /**
     * ATLauncher has no search API: its public pack list is read once (cached) and filtered
     * here by name or description and, when given, by Minecraft version. Newest first.
     */
    public function search(?string $query, ?string $minecraftVersion, ?string $loader, array $categories = [], ?string $sort = null, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $query = mb_strtolower(mb_substr(trim((string) $query), 0, 100));

        $packs = array_values(array_filter($this->packs(), function (array $pack) use ($query, $minecraftVersion) {
            if ($query !== '' && !str_contains(mb_strtolower($pack['name']), $query) && !str_contains(mb_strtolower($pack['description']), $query)) {
                return false;
            }

            return $minecraftVersion === null || in_array($minecraftVersion, array_column($pack['versions'], 'minecraft'), true);
        }));

        usort($packs, fn (array $a, array $b) => ($b['versions'][0]['published'] ?? 0) <=> ($a['versions'][0]['published'] ?? 0));

        $hits = [];
        foreach (array_slice($packs, (max(1, $page) - 1) * $perPage, $perPage) as $pack) {
            $hits[] = [
                'id' => $pack['id'],
                'slug' => $pack['id'],
                'title' => $pack['name'],
                'description' => mb_strimwidth($pack['description'], 0, 300, '…'),
                'author' => '',
                'icon_url' => null,
                'downloads' => 0,
                'follows' => 0,
                'date_modified' => self::date($pack['versions'][0]['published'] ?? null),
                'categories' => [],
                'game_versions' => array_values(array_unique(array_slice(array_column($pack['versions'], 'minecraft'), 0, 1))),
            ];
        }

        return ['hits' => $hits, 'total' => count($packs)];
    }

    public function project(string $packId): array
    {
        $pack = $this->pack($packId);
        $latest = $pack['versions'][0] ?? null;

        return [
            'id' => $pack['id'],
            'slug' => $pack['id'],
            'title' => $pack['name'],
            'description' => mb_strimwidth($pack['description'], 0, 300, '…'),
            'body' => $pack['description'],
            'icon_url' => null,
            'gallery' => [],
            'game_versions' => $latest !== null ? [$latest['minecraft']] : [],
            'loaders' => $latest !== null ? $this->loaderOf($pack['id'], $latest['version']) : [],
            'downloads' => 0,
            'website_url' => null,
        ];
    }

    public function versions(string $packId): array
    {
        $pack = $this->pack($packId);

        $versions = [];
        foreach ($pack['versions'] as $index => $version) {
            // The loader is only in each version's Configs.json: looked up for the newest few.
            $loaders = $index < self::MAX_LOADER_LOOKUPS ? $this->loaderOf($pack['id'], $version['version']) : [];
            $versions[] = $this->normaliseVersion($pack['id'], $version, $loaders);
        }

        return $versions;
    }

    public function packVersion(string $packId, string $versionId): array
    {
        $version = $this->listedVersion($packId, $versionId);
        [, $loader] = self::loader($this->configs($packId, $versionId), $version['minecraft']);

        return $this->normaliseVersion($packId, $version, [$loader]);
    }

    public function fetchArchive(PackArchive $archive, string $path, PlanValidator $validator): void
    {
        // Host-agnostic: the validator's allowlist (ATLauncher hosts) and the archive's size and sha1 decide.
        $this->modrinth->fetchArchive($archive, $path, $validator);
    }

    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan
    {
        $pack = $this->pack($packId, fresh: true);
        $version = $this->listedVersion($packId, $versionId, $pack);
        $configs = $this->configs($packId, $versionId);
        [$loaderVersion, $loader] = self::loader($configs, $version['minecraft']);

        $archive = null;
        if (empty($configs['noConfigs'])) {
            $archive = self::configsArchive($packId, $versionId, $configs['configs'] ?? null);
        }

        $plan = new InstallPlan(
            provider: self::ID,
            packId: $packId,
            versionId: $versionId,
            packName: $pack['name'],
            versionNumber: $versionId,
            minecraftVersion: $version['minecraft'],
            loader: $loader,
            loaderVersion: $loaderVersion,
            archive: $archive,
            // Configs.zip holds the server files at its root (see InstallPlan::overrideRoot()).
            serverPackRoot: $archive !== null ? '' : null,
        );

        // URL, declared size and hash format are checked before anything is downloaded.
        $validator->validate($plan);

        [$candidates, $manual, $skippedClient] = $this->candidates($configs['mods'] ?? null, $validator);
        $resolved = $this->resolver->resolve($candidates, self::DOWNLOAD_HOSTS);

        $overrides = [];
        if ($archive !== null) {
            $path = rtrim($workDir, '/').'/'.PackArchive::WORK_FILE;
            $this->fetchArchive($archive, $path, $validator);

            $reader = PackArchiveReader::open($path, $validator, $this->caps);
            try {
                foreach ($reader->names() as $name) {
                    $target = $validator->overrideTarget($name, '');
                    if ($target !== null) {
                        $overrides[] = new OverrideEntry($name, $target, (int) $reader->size($name));
                    }
                }
            } finally {
                $reader->close();
            }
        }

        $plan = $validator->validate($plan->with([
            'files' => $resolved['files'],
            'overrides' => $overrides,
            'skippedClientFiles' => $skippedClient,
            'manualFiles' => array_merge($manual, $resolved['manual']),
            'clientOnlyBy' => $resolved['client_only'],
        ]));

        return $validator->validate($this->modrinth->assignVerification($plan));
    }

    /**
     * The mods a server gets, as PackFileResolver candidates, plus the ones listed for the
     * user and the client-only ones left out.
     *
     * @return array{array<int, array<string, mixed>>, array<int, array{name: string, url: ?string, reason: string}>, array<int, string>}
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function candidates(mixed $mods, PlanValidator $validator): array
    {
        if ($mods === null) {
            $mods = [];
        }
        if (!is_array($mods) || !array_is_list($mods)) {
            throw new PackUnavailableException('bad_index', 'mods');
        }
        if (count($mods) > $this->caps->maxFiles) {
            throw new UnsafePlanException('too_many_files', (string) count($mods));
        }

        $candidates = [];
        $manual = [];
        $skippedClient = [];
        foreach ($mods as $mod) {
            if (!is_array($mod)) {
                throw new PackUnavailableException('bad_index', 'mods');
            }

            $file = is_string($mod['file'] ?? null) ? $mod['file'] : '';
            $name = is_string($mod['name'] ?? null) && $mod['name'] !== '' ? $mod['name'] : $file;
            $type = $mod['type'] ?? null;
            $link = CurseForgePackProvider::websiteUrl($mod['website'] ?? null);

            if (!CurseForgePackProvider::isSafeFileName($file)) {
                throw new UnsafePlanException('unsafe_path', $file);
            }

            // Kept unless the pack says the server doesn't use it.
            if (($mod['server'] ?? null) === false || in_array($type, self::CLIENT_TYPES, true)) {
                $skippedClient[] = ($type === 'mods' ? 'mods/' : '').$file;

                continue;
            }
            if (($mod['optional'] ?? false) === true) {
                continue;
            }
            if ($type === 'datapack') {
                $manual[] = ['name' => $name, 'url' => $link, 'reason' => 'datapack'];

                continue;
            }
            if ($type !== 'mods' || ($mod['serverSeparate'] ?? false) === true) {
                $manual[] = ['name' => $name, 'url' => $link, 'reason' => 'unsupported_type'];

                continue;
            }
            if (!CurseForgePackProvider::hasExtension($file, ['jar', 'zip'])) {
                throw new UnsafePlanException('unsafe_path', $file);
            }

            $path = $validator->normalisePath('mods/'.$file);
            if (PlanValidator::isModrinthMetadata($path)) {
                throw new UnsafePlanException('pack_ships_metadata', $path);
            }

            $download = $mod['download'] ?? null;
            if ($download === 'browser') {
                $manual[] = ['name' => $name, 'url' => $link, 'reason' => 'distribution_disabled'];

                continue;
            }
            $url = match ($download) {
                'server' => self::cdnUrl($mod['url'] ?? null),
                'direct' => is_string($mod['url'] ?? null) && $mod['url'] !== '' ? $mod['url'] : null,
                default => false,
            };
            if ($url === false) {
                $manual[] = ['name' => $name, 'url' => $link, 'reason' => 'unsupported_type'];

                continue;
            }

            $project = CurseForgePackProvider::intId($mod['curse_id'] ?? null);
            $fileId = CurseForgePackProvider::intId($mod['curse_file_id'] ?? null);
            $reference = $project !== null && $fileId !== null ? ['project' => $project, 'file' => $fileId] : null;

            $candidates[] = [
                'path' => $path,
                'display' => $name,
                'url' => $url,
                'size' => is_int($mod['filesize'] ?? null) && $mod['filesize'] >= 0 ? $mod['filesize'] : -1,
                // ATLauncher's own sha1 when it has one. Its md5 is only used for a file served from
                // its own CDN (`download: server`) when no sha1 exists anywhere (PackFileResolver).
                'sha1' => $mod['sha1'] ?? null,
                'md5' => $download === 'server' ? ($mod['md5'] ?? null) : null,
                'side' => 'required',
                'curseforge' => $reference,
                'cf_hosted' => $reference !== null && $url !== null && in_array(parse_url($url, PHP_URL_HOST), CurseForgePackProvider::DOWNLOAD_HOSTS, true),
                'link' => $link,
            ];
        }

        return [$candidates, $manual, $skippedClient];
    }

    /**
     * A `download: server` path (relative to the CDN) as an absolute CDN URL, every segment
     * plain and percent-encoded. Null for anything else (listed as on another host).
     */
    public static function cdnUrl(mixed $path): ?string
    {
        // Older packs write `files//x.jar`: repeated slashes are collapsed (ATLauncher CDN paths only),
        // then the usual path checks apply.
        // No scheme, host, query or fragment (checked before collapsing, so `https://x` can't become a path).
        if (!is_string($path) || $path === '' || strlen($path) > 1024 || str_starts_with($path, '/') || preg_match('/[?#:]/', $path)) {
            return null;
        }
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        if (!PlanValidator::isSafeRelativePath($path)) {
            return null;
        }

        return self::CDN_URL.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * The configs archive of a version: Configs.zip on the CDN, with the size and sha1
     * Configs.json gives (a missing or malformed sha1 stops the install).
     *
     * @throws UnsafePlanException
     */
    public static function configsArchive(string $packId, string $versionId, mixed $configs): PackArchive
    {
        $configs = is_array($configs) ? $configs : [];

        return new PackArchive(
            self::CDN_URL.'/packs/'.rawurlencode($packId).'/versions/'.rawurlencode($versionId).'/Configs.zip',
            'Configs.zip',
            is_int($configs['filesize'] ?? null) ? $configs['filesize'] : -1,
            is_string($configs['sha1'] ?? null) ? ['sha1' => $configs['sha1']] : [],
        );
    }

    /**
     * The loader version and loader of a Configs.json, which must be for $minecraft.
     *
     * @param  array<mixed>  $configs
     * @return array{string, string} [loader version, loader]
     *
     * @throws PackUnavailableException
     */
    public static function loader(array $configs, string $minecraft): array
    {
        if (($configs['minecraft'] ?? null) !== $minecraft) {
            throw new PackUnavailableException('bad_index', 'minecraft');
        }

        $loader = is_array($configs['loader'] ?? null) ? $configs['loader'] : [];
        $type = is_string($loader['type'] ?? null) ? strtolower($loader['type']) : '';
        $metadata = is_array($loader['metadata'] ?? null) ? $loader['metadata'] : [];
        $version = $metadata['version'] ?? ($metadata['loader'] ?? null);

        if (!in_array($type, ServerTarget::LOADERS, true) || !empty($loader['choose']) || !is_string($version) || !preg_match('/^[0-9A-Za-z.+\-_]{1,64}$/', $version)
            || (isset($metadata['minecraft']) && $metadata['minecraft'] !== $minecraft)) {
            throw new PackUnavailableException('bad_index', 'loader');
        }

        return [$version, $type];
    }

    /**
     * Loaders of a version for display (cached); [] when Configs.json can't be read.
     *
     * @return array<int, string>
     */
    private function loaderOf(string $packId, string $versionId): array
    {
        return Cache::remember('modpacks-plus:atlauncher:loader:'.$packId.':'.$versionId, now()->addHour(), function () use ($packId, $versionId) {
            try {
                $configs = $this->configs($packId, $versionId);

                return [self::loader($configs, is_string($configs['minecraft'] ?? null) ? $configs['minecraft'] : '')[1]];
            } catch (PackUnavailableException) {
                return [];
            }
        });
    }

    /**
     * A version's Configs.json (not cached when installing).
     *
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function configs(string $packId, string $versionId): array
    {
        $configs = $this->cdn->get(['packs', $packId, 'versions', $versionId, 'Configs.json'], [], 20);
        if (($configs['version'] ?? $versionId) !== $versionId) {
            throw new PackUnavailableException('version_mismatch', $versionId);
        }

        return $configs;
    }

    /**
     * @param  array{id: string, name: string, description: string, versions: array<int, array{version: string, minecraft: string, published: ?int}>}|null  $pack
     * @return array{version: string, minecraft: string, published: ?int}
     *
     * @throws PackUnavailableException
     */
    private function listedVersion(string $packId, string $versionId, ?array $pack = null): array
    {
        if (!self::isValidId($versionId)) {
            throw new PackUnavailableException('atlauncher_not_found', $versionId);
        }

        foreach (($pack ?? $this->pack($packId))['versions'] as $version) {
            if ($version['version'] === $versionId) {
                return $version;
            }
        }

        throw new PackUnavailableException('version_mismatch', $versionId);
    }

    /**
     * One public pack (from the cached list, or read again when installing).
     *
     * @return array{id: string, name: string, description: string, versions: array<int, array{version: string, minecraft: string, published: ?int}>}
     *
     * @throws PackUnavailableException
     */
    private function pack(string $packId, bool $fresh = false): array
    {
        if (!self::isValidId($packId)) {
            throw new PackUnavailableException('atlauncher_not_found', $packId);
        }

        if ($fresh) {
            $response = $this->api->get(['pack', $packId]);
            $pack = is_array($response['data'] ?? null) ? self::normalisePack($response['data']) : null;
        } else {
            $pack = null;
            foreach ($this->packs() as $listed) {
                if ($listed['id'] === $packId) {
                    $pack = $listed;
                }
            }
        }

        if ($pack === null || $pack['id'] !== $packId) {
            throw new PackUnavailableException('atlauncher_not_found', $packId);
        }

        return $pack;
    }

    /**
     * Every public pack (cached), normalised.
     *
     * @return array<int, array{id: string, name: string, description: string, versions: array<int, array{version: string, minecraft: string, published: ?int}>}>
     *
     * @throws PackUnavailableException
     */
    private function packs(): array
    {
        /** @var array<int, array{id: string, name: string, description: string, versions: array<int, array{version: string, minecraft: string, published: ?int}>}> $packs */
        $packs = Cache::remember('modpacks-plus:atlauncher:packs', now()->addMinutes(self::CACHE_MINUTES), function () {
            $response = $this->api->get(['packs', 'full', 'public'], [], 20);

            $packs = [];
            foreach (array_slice(is_array($response['data'] ?? null) ? array_values($response['data']) : [], 0, self::MAX_PACKS) as $pack) {
                $pack = is_array($pack) ? self::normalisePack($pack) : null;
                if ($pack !== null) {
                    $packs[] = $pack;
                }
            }

            return $packs;
        });

        return $packs;
    }

    /**
     * @param  array<mixed>  $pack
     * @return array{id: string, name: string, description: string, versions: array<int, array{version: string, minecraft: string, published: ?int}>}|null
     */
    private static function normalisePack(array $pack): ?array
    {
        $id = $pack['safeName'] ?? null;
        if (!is_string($id) || !self::isValidId($id) || ($pack['type'] ?? null) !== 'public') {
            return null;
        }

        $versions = [];
        foreach (array_slice(is_array($pack['versions'] ?? null) ? array_values($pack['versions']) : [], 0, self::MAX_VERSIONS) as $version) {
            if (is_array($version) && is_string($version['version'] ?? null) && self::isValidId($version['version'])
                && is_string($version['minecraft'] ?? null) && preg_match('/^[A-Za-z0-9.\-_+]{1,64}$/', $version['minecraft'])) {
                $versions[] = [
                    'version' => $version['version'],
                    'minecraft' => $version['minecraft'],
                    'published' => is_int($version['published'] ?? null) ? $version['published'] : null,
                ];
            }
        }
        usort($versions, fn (array $a, array $b) => ($b['published'] ?? 0) <=> ($a['published'] ?? 0));

        return [
            'id' => $id,
            'name' => is_string($pack['name'] ?? null) && $pack['name'] !== '' ? mb_substr($pack['name'], 0, 200) : $id,
            'description' => is_string($pack['description'] ?? null) ? mb_substr($pack['description'], 0, self::MAX_DESCRIPTION_CHARS) : '',
            'versions' => $versions,
        ];
    }

    /**
     * @param  array{version: string, minecraft: string, published: ?int}  $version
     * @param  array<int, string>  $loaders
     * @return PackVersion
     */
    private function normaliseVersion(string $packId, array $version, array $loaders): array
    {
        return [
            'id' => $version['version'],
            'project_id' => $packId,
            'name' => $version['version'],
            'version_number' => $version['version'],
            'version_type' => 'release',
            'game_versions' => [$version['minecraft']],
            'loaders' => $loaders,
            'date_published' => self::date($version['published']),
            'downloads' => 0,
            'changelog' => null,
            'file' => null,
        ];
    }

    private static function date(mixed $timestamp): ?string
    {
        return is_int($timestamp) && $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }
}
