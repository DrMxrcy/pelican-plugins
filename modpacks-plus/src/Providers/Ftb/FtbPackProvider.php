<?php

namespace DrMxrcy\ModpacksPlus\Providers\Ftb;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\PackFileResolver;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JsonApiClient;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Support\Facades\Cache;

/**
 * Feed The Beast modpacks (plan 32), read from the public modpacks.ch API
 * (https://api.modpacks.ch/public): public packs only, never private ones.
 *
 * An FTB pack version is a flat list of files (mods, configs, scripts, resources), each with
 * a path, a name, a size, a sha1 and a URL, so there is no pack archive and no overrides:
 * every file is a download. FTB hosts its own files on dist.modpacks.ch (no Content-Length,
 * so the node can't pull them: the panel fetches each one, byte-capped, and checks its
 * sha1). Mods FTB takes from CurseForge carry a CurseForge project and file id: they go
 * through plan 31's rules (Modrinth-first by sha1, else the URL CurseForge's API builds for
 * that file id, never when the author disallows it; without a key or the mirror they are
 * listed as "needs CurseForge key"). See PackFileResolver.
 *
 * Left out: `clientonly` files, `optional` files (as CurseForge's `required: false`), the
 * pack's own top-level scripts, jars and dotfiles, client-only folders and anything aimed at
 * a world, a player list, server.properties or `.modpacks-plus/` (listed, never written).
 * Everything from the API is untrusted: ids are plain numbers, paths go through
 * PlanValidator, image links must be HTTPS.
 *
 * @phpstan-import-type PackHit from PackProvider
 * @phpstan-import-type PackVersion from PackProvider
 */
class FtbPackProvider implements PackProvider
{
    public const ID = 'ftb';

    public const API_URL = 'https://api.modpacks.ch/public';

    /** Where FTB serves its own pack files from (panel-fetched only). */
    public const DOWNLOAD_HOSTS = ['dist.modpacks.ch'];

    /** File types FTB lists that are placed as they are. */
    public const FILE_TYPES = ['mod', 'config', 'resource', 'script'];

    /** Packs asked for per search (FTB has fewer than a hundred public packs). */
    private const MAX_RESULTS = 100;

    private const MAX_PAGE_SIZE = 20;

    private const MAX_VERSIONS = 200;

    private const MAX_SMALL_LIST = 100;

    private const CACHE_MINUTES = 10;

    private const MAX_BODY_CHARS = 20000;

    private const RELEASE_TYPES = ['release', 'beta', 'alpha'];

    public function __construct(
        private readonly JsonApiClient $client,
        private readonly PackFileResolver $resolver,
        private readonly ModrinthPackProvider $modrinth,
        private readonly Caps $caps,
    ) {}

    public static function apiClient(): JsonApiClient
    {
        return new JsonApiClient(self::API_URL, 'ftb_unavailable', 'ftb_not_found');
    }

    public function id(): string
    {
        return self::ID;
    }

    /** FTB pack and version ids are positive integers. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{0,9}$/', $id) && (int) $id <= 2147483647;
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
     * FTB's own search (or, without a query, its most installed packs), one page of pack
     * details. FTB has no loader or version filter: every pack is listed, and the detail view
     * says what a version needs.
     */
    public function search(?string $query, ?string $minecraftVersion, ?string $loader, array $categories = [], ?string $sort = null, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $query = $query !== null ? trim($query) : '';

        $response = $query !== ''
            ? $this->client->get(['modpack', 'search', (string) self::MAX_RESULTS], ['term' => mb_substr($query, 0, 64)])
            : $this->client->get(['modpack', 'popular', 'installs', (string) self::MAX_RESULTS]);

        $ids = [];
        foreach (self::items($response['packs'] ?? null, self::MAX_RESULTS) as $id) {
            $id = self::intId($id);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);

        $hits = [];
        foreach (array_slice($ids, (max(1, $page) - 1) * $perPage, $perPage) as $id) {
            try {
                $hits[] = $this->normaliseHit($this->pack((string) $id));
            } catch (PackUnavailableException $exception) {
                if ($exception->reason !== 'ftb_not_found') {
                    throw $exception;
                }
            }
        }

        return ['hits' => $hits, 'total' => count($ids)];
    }

    public function project(string $packId): array
    {
        $pack = $this->pack($packId);
        [$gameVersions, $loaders] = self::latestTargets($pack);

        $gallery = [];
        foreach (self::items($pack['art'] ?? null, self::MAX_SMALL_LIST) as $art) {
            $url = is_array($art) && ($art['type'] ?? null) === 'splash' ? self::imageUrl($art['url'] ?? null) : null;
            if ($url !== null) {
                $gallery[] = ['url' => $url, 'title' => null];
            }
        }

        return [
            'id' => $packId,
            'slug' => $packId,
            'title' => self::string($pack['name'] ?? null, $packId),
            'description' => self::string($pack['synopsis'] ?? null),
            'body' => mb_substr(self::string($pack['description'] ?? null), 0, self::MAX_BODY_CHARS),
            'icon_url' => self::art($pack, 'square'),
            'gallery' => $gallery,
            'game_versions' => $gameVersions,
            'loaders' => $loaders,
            'downloads' => is_int($pack['installs'] ?? null) ? $pack['installs'] : 0,
            'website_url' => null,
        ];
    }

    public function versions(string $packId): array
    {
        $versions = [];
        foreach (self::items($this->pack($packId)['versions'] ?? null, self::MAX_VERSIONS) as $version) {
            if (is_array($version) && self::intId($version['id'] ?? null) !== null && empty($version['private'])) {
                $versions[] = $this->normaliseVersion($version, $packId);
            }
        }

        usort($versions, fn (array $a, array $b) => strcmp((string) $b['date_published'], (string) $a['date_published']) ?: ((int) $b['id'] <=> (int) $a['id']));

        return $versions;
    }

    public function packVersion(string $packId, string $versionId): array
    {
        return $this->normaliseVersion($this->version($packId, $versionId), $packId);
    }

    /** FTB packs have no archive (every file is a download); nothing is ever fetched here. */
    public function fetchArchive(PackArchive $archive, string $path, PlanValidator $validator): void
    {
        throw new PackUnavailableException('no_pack_file', $archive->filename);
    }

    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan
    {
        $version = $this->version($packId, $versionId);
        $pack = $this->pack($packId);
        [$minecraft, $loader, $loaderVersion] = self::target($version['targets'] ?? null);

        $list = $version['files'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new PackUnavailableException('bad_index', 'files');
        }
        // Raw entries, before client-only ones are dropped; the validator caps what is left.
        if (count($list) > $this->caps->maxArchiveEntries) {
            throw new UnsafePlanException('too_many_files', (string) count($list));
        }

        $candidates = [];
        $manual = [];
        $skippedClient = [];
        $skippedClientOverrides = [];
        $skippedProtected = [];
        $skippedPack = [];
        foreach ($list as $file) {
            if (!is_array($file)) {
                throw new PackUnavailableException('bad_index', 'files');
            }

            $name = is_string($file['name'] ?? null) ? $file['name'] : '';
            $path = self::filePath($file['path'] ?? null, $name, $validator);

            // Plan 33: only a broken or hostile pack ships the file Modrinth Plus trusts.
            if (PlanValidator::isModrinthMetadata($path)) {
                throw new UnsafePlanException('pack_ships_metadata', $path);
            }

            if (!empty($file['clientonly'])) {
                $skippedClient[] = $path;

                continue;
            }
            if (!empty($file['optional'])) {
                continue;
            }
            if (PlanValidator::isServerPackLauncherFile($path)) {
                $skippedPack[] = $path;

                continue;
            }
            if (PlanValidator::isClientOnlyOverride($path)) {
                $skippedClientOverrides[] = $path;

                continue;
            }
            if ($validator->isProtected($path)) {
                $skippedProtected[] = $path;

                continue;
            }

            $type = $file['type'] ?? null;
            if (!in_array($type, self::FILE_TYPES, true)) {
                $manual[] = ['name' => $name, 'url' => null, 'reason' => 'unsupported_type'];

                continue;
            }
            if ($type === 'mod' && !CurseForgePackProvider::hasExtension($name, ['jar', 'zip'])) {
                throw new UnsafePlanException('unsafe_path', $path);
            }

            $curseforge = is_array($file['curseforge'] ?? null) ? $file['curseforge'] : [];
            $project = CurseForgePackProvider::intId($curseforge['project'] ?? null);
            $fileId = CurseForgePackProvider::intId($curseforge['file'] ?? null);
            $reference = $project !== null && $fileId !== null ? ['project' => $project, 'file' => $fileId] : null;
            $url = is_string($file['url'] ?? null) && $file['url'] !== '' ? $file['url'] : null;

            $candidates[] = [
                'path' => $path,
                'display' => $name,
                'url' => $url,
                'size' => is_int($file['size'] ?? null) && $file['size'] >= 0 ? $file['size'] : -1,
                'sha1' => $file['sha1'] ?? null,
                'side' => 'required',
                'curseforge' => $reference,
                // FTB's URL for a CurseForge file is never used: CurseForge's API builds it.
                'cf_hosted' => $reference !== null && ($url === null || in_array(parse_url($url, PHP_URL_HOST), CurseForgePackProvider::DOWNLOAD_HOSTS, true)),
            ];
        }

        $resolved = $this->resolver->resolve($candidates, self::DOWNLOAD_HOSTS);

        $plan = $validator->validate(new InstallPlan(
            provider: self::ID,
            packId: $packId,
            versionId: $versionId,
            packName: self::string($pack['name'] ?? null, $packId),
            versionNumber: self::string($version['name'] ?? null, $versionId),
            minecraftVersion: $minecraft,
            loader: $loader,
            loaderVersion: $loaderVersion,
            files: $resolved['files'],
            skippedClientFiles: $skippedClient,
            skippedOverrides: $skippedProtected,
            skippedClientOverrides: $skippedClientOverrides,
            manualFiles: array_merge($manual, $resolved['manual']),
            skippedPackFiles: $skippedPack,
            clientOnlyBy: $resolved['client_only'],
        ));

        // Files on cdn.modrinth.com whose sha512 Modrinth vouches for at that URL may be pulled by
        // the node; everything else (dist.modpacks.ch, forgecdn) is fetched and sha1-checked by the panel.
        return $validator->validate($this->modrinth->assignVerification($plan));
    }

    /**
     * Where an FTB file goes: its `path` (`./mods`, `./config/x`, `.` for the root) plus its
     * `name`, a single plain file name. Anything else is refused.
     *
     * @throws UnsafePlanException
     */
    public static function filePath(mixed $path, string $name, PlanValidator $validator): string
    {
        if (!is_string($path) || $name === '' || str_contains($name, '/') || !PlanValidator::isSafeRelativePath($name)) {
            throw new UnsafePlanException('unsafe_path', (is_string($path) ? $path : '').'/'.$name);
        }

        $directory = $path;
        if ($directory === '.' || $directory === './') {
            $directory = '';
        } elseif (str_starts_with($directory, './')) {
            $directory = substr($directory, 2);
        }
        if (str_ends_with($directory, '/')) {
            $directory = substr($directory, 0, -1);
        }

        return $validator->normalisePath($directory === '' ? $name : $directory.'/'.$name);
    }

    /**
     * The Minecraft version, the one loader and its version a pack version targets.
     *
     * @return array{string, string, string}
     *
     * @throws PackUnavailableException
     */
    public static function target(mixed $targets): array
    {
        $minecraft = null;
        $loaders = [];
        foreach (self::items($targets, self::MAX_SMALL_LIST) as $target) {
            if (!is_array($target) || !is_string($target['name'] ?? null) || !is_string($target['version'] ?? null)) {
                continue;
            }
            if (($target['type'] ?? null) === 'game' && $target['name'] === 'minecraft') {
                $minecraft = $target['version'];
            } elseif (($target['type'] ?? null) === 'modloader') {
                $loaders[] = [strtolower($target['name']), $target['version']];
            }
        }

        if (!is_string($minecraft) || !preg_match('/^[A-Za-z0-9.\-_+]{1,64}$/', $minecraft)) {
            throw new PackUnavailableException('bad_index', 'targets.minecraft');
        }
        if (count($loaders) !== 1 || !in_array($loaders[0][0], ServerTarget::LOADERS, true) || !preg_match('/^[0-9A-Za-z.+\-_]{1,64}$/', $loaders[0][1])) {
            throw new PackUnavailableException('bad_index', 'targets.modloader');
        }

        return [$minecraft, $loaders[0][0], $loaders[0][1]];
    }

    /**
     * One public pack's details (cached).
     *
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    protected function pack(string $packId): array
    {
        $this->assertId($packId);

        /** @var array<mixed> $pack */
        $pack = Cache::remember('modpacks-plus:ftb:pack:'.$packId, now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $pack = $this->client->get(['modpack', $packId]);
            if ((string) self::intId($pack['id'] ?? null) !== $packId || ($pack['status'] ?? 'success') !== 'success' || !empty($pack['private'])) {
                throw new PackUnavailableException('ftb_not_found', $packId);
            }

            return $pack;
        });

        return $pack;
    }

    /**
     * One public version of $packId, with its file list (not cached: it is read to install).
     *
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    protected function version(string $packId, string $versionId): array
    {
        $this->assertId($packId);
        $this->assertId($versionId);

        $version = $this->client->get(['modpack', $packId, $versionId], [], 20);
        if ((string) self::intId($version['id'] ?? null) !== $versionId || ($version['status'] ?? 'success') !== 'success' || !empty($version['private'])) {
            throw new PackUnavailableException('ftb_not_found', $packId.'/'.$versionId);
        }
        if ((string) self::intId($version['parent'] ?? null) !== $packId) {
            throw new PackUnavailableException('version_mismatch', $versionId);
        }

        return $version;
    }

    /**
     * @param  array<mixed>  $pack
     * @return PackHit
     */
    private function normaliseHit(array $pack): array
    {
        $id = (string) self::intId($pack['id'] ?? null);
        $authors = self::items($pack['authors'] ?? null, 1);
        $categories = [];
        foreach (self::items($pack['tags'] ?? null, self::MAX_SMALL_LIST) as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $categories[] = $tag['name'];
            }
        }

        return [
            'id' => $id,
            'slug' => $id,
            'title' => self::string($pack['name'] ?? null, $id),
            'description' => self::string($pack['synopsis'] ?? null),
            'author' => is_array($authors[0] ?? null) ? self::string($authors[0]['name'] ?? null) : '',
            'icon_url' => self::art($pack, 'square'),
            'downloads' => is_int($pack['installs'] ?? null) ? $pack['installs'] : 0,
            'follows' => 0,
            'date_modified' => self::date($pack['updated'] ?? null),
            'categories' => $categories,
            'game_versions' => self::latestTargets($pack)[0],
        ];
    }

    /**
     * @param  array<mixed>  $version
     * @return PackVersion
     */
    private function normaliseVersion(array $version, string $packId): array
    {
        [$gameVersions, $loaders] = self::targets($version['targets'] ?? null);
        $type = is_string($version['type'] ?? null) ? strtolower($version['type']) : 'release';
        $name = self::string($version['name'] ?? null, (string) self::intId($version['id'] ?? null));

        return [
            'id' => (string) self::intId($version['id'] ?? null),
            'project_id' => $packId,
            'name' => $name,
            'version_number' => $name,
            'version_type' => in_array($type, self::RELEASE_TYPES, true) ? $type : 'release',
            'game_versions' => $gameVersions,
            'loaders' => $loaders,
            'date_published' => self::date($version['updated'] ?? null),
            'downloads' => 0,
            'changelog' => null,
            'file' => null,
        ];
    }

    /**
     * Minecraft versions and loaders a version's targets name (for display and matching).
     *
     * @return array{array<int, string>, array<int, string>}
     */
    private static function targets(mixed $targets): array
    {
        $minecraft = [];
        $loaders = [];
        foreach (self::items($targets, self::MAX_SMALL_LIST) as $target) {
            if (!is_array($target) || !is_string($target['name'] ?? null) || !is_string($target['version'] ?? null)) {
                continue;
            }
            if (($target['type'] ?? null) === 'game' && $target['name'] === 'minecraft' && preg_match('/^[A-Za-z0-9.\-_+]{1,64}$/', $target['version'])) {
                $minecraft[] = $target['version'];
            } elseif (($target['type'] ?? null) === 'modloader' && in_array(strtolower($target['name']), ServerTarget::LOADERS, true)) {
                $loaders[] = strtolower($target['name']);
            }
        }

        return [array_values(array_unique($minecraft)), array_values(array_unique($loaders))];
    }

    /**
     * Targets of the pack's newest public version.
     *
     * @param  array<mixed>  $pack
     * @return array{array<int, string>, array<int, string>}
     */
    private static function latestTargets(array $pack): array
    {
        $latest = null;
        foreach (self::items($pack['versions'] ?? null, self::MAX_VERSIONS) as $version) {
            if (is_array($version) && empty($version['private']) && ($latest === null || (int) ($version['updated'] ?? 0) >= (int) ($latest['updated'] ?? 0))) {
                $latest = $version;
            }
        }

        return $latest !== null ? self::targets($latest['targets'] ?? null) : [[], []];
    }

    /** @param  array<mixed>  $pack */
    private static function art(array $pack, string $type): ?string
    {
        foreach (self::items($pack['art'] ?? null, self::MAX_SMALL_LIST) as $art) {
            if (is_array($art) && ($art['type'] ?? null) === $type && ($url = self::imageUrl($art['url'] ?? null)) !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function imageUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= 2048 && !preg_match('/[\s"\'<>\\\\]/', $url) ? $url : null;
    }

    private static function date(mixed $timestamp): ?string
    {
        return is_int($timestamp) && $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** A positive integer id from JSON (int or numeric string), else null. */
    private static function intId(mixed $value): ?int
    {
        return CurseForgePackProvider::intId($value);
    }

    /**
     * At most $max entries of an API list (anything else is no entries).
     *
     * @return array<int, mixed>
     */
    private static function items(mixed $list, int $max): array
    {
        return is_array($list) ? array_slice(array_values($list), 0, max(0, $max)) : [];
    }

    /** @throws PackUnavailableException */
    private function assertId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new PackUnavailableException('ftb_not_found', $id);
        }
    }
}
