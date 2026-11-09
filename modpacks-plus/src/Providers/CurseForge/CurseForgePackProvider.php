<?php

namespace DrMxrcy\ModpacksPlus\Providers\CurseForge;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\ModrinthMatcher;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Support\Facades\Cache;

/**
 * CurseForge modpacks (Minecraft, class "Modpacks"), read through CurseForgeClient.
 *
 * Everything coming back from the API is treated as untrusted: ids must be plain
 * numbers, image and page links must be HTTPS on the expected hosts, and download URLs
 * are rebuilt from their parts (edge/mediafilez.forgecdn.net/files/<n>/<n>/<name>) before
 * PlanValidator sees them.
 *
 * @phpstan-import-type PackHit from PackProvider
 * @phpstan-import-type PackVersion from PackProvider
 */
class CurseForgePackProvider implements PackProvider
{
    public const ID = 'curseforge';

    public const GAME_MINECRAFT = 432;

    public const CLASS_MODPACKS = 4471;

    /** CurseForge ModLoaderType values for the loaders eggs know. */
    public const LOADER_TYPES = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    /** Sort key => [ModsSearchSortField, order]. */
    public const SORTS = [
        'relevance' => [1, 'desc'],
        'downloads' => [6, 'desc'],
        'updated' => [3, 'desc'],
        'newest' => [11, 'desc'],
        'name' => [4, 'asc'],
    ];

    /** CurseForge FileReleaseType => version_type. */
    private const RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    /** CurseForge HashAlgo 1 (sha1); md5 (2) is never used for verification. */
    public const HASH_SHA1 = 1;

    /** Hosts CurseForge serves files from; also the only hosts PlanValidator allows besides cdn.modrinth.com. */
    public const DOWNLOAD_HOSTS = ['edge.forgecdn.net', 'mediafilez.forgecdn.net'];

    /** CurseForge caps index + pageSize at 10 000 and pageSize at 50. */
    private const MAX_RESULTS = 10000;

    private const MAX_PAGE_SIZE = 50;

    private const MAX_CATEGORY_FILTERS = 10;

    private const MAX_CATEGORIES = 500;

    private const MAX_SCREENSHOTS = 20;

    private const MAX_SMALL_LIST = 100;

    private const CACHE_MINUTES = 10;

    private const MAX_BODY_CHARS = 20000;

    private const MANIFEST_FILE = 'manifest.json';

    private const MAX_MANIFEST_BYTES = 8 * 1024 * 1024;

    /** Ids per batch request (POST /mods/files, POST /mods). */
    private const RESOLVE_CHUNK = 100;

    /** Extensions of files placed on the server, and of pack / server-pack archives. */
    private const FILE_EXTENSIONS = ['jar', 'zip'];

    private const ARCHIVE_EXTENSIONS = ['zip'];

    /** CurseForge class ids => server folder. */
    public const CLASS_FOLDERS = [6 => 'mods', 12 => 'resourcepacks', 6552 => 'shaderpacks'];

    /** Classes only a game client uses (never written). */
    private const CLIENT_CLASSES = [12, 6552];

    /** Data packs belong inside a world, which an install never writes: listed for the user. */
    public const CLASS_DATAPACKS = 6945;

    /** A server pack failing one of these falls back to the manifest build instead of failing the install. */
    private const SERVER_PACK_FALLBACK_REASONS = ['archive_too_large', 'too_many_entries', 'file_too_large', 'override_too_large', 'total_too_large', 'too_many_files'];

    /** Top-level folders a server pack itself has (so a lone one is not a wrapper folder). */
    private const SERVER_FOLDERS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'resources', 'datapacks'];

    public function __construct(
        private readonly CurseForgeClient $client,
        private readonly ModrinthPackProvider $modrinth,
        private readonly ModrinthMatcher $matcher,
        private readonly Caps $caps,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function client(): CurseForgeClient
    {
        return $this->client;
    }

    /** CurseForge ids are positive integers. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{0,9}$/', $id) && (int) $id <= 2147483647;
    }

    public function sorts(): array
    {
        return array_keys(self::SORTS);
    }

    public function search(?string $query, ?string $minecraftVersion, ?string $loader, array $categories = [], ?string $sort = null, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $index = (max(1, $page) - 1) * $perPage;
        if ($index + $perPage > self::MAX_RESULTS) {
            return ['hits' => [], 'total' => self::MAX_RESULTS];
        }

        [$sortField, $sortOrder] = self::SORTS[$sort ?? 'relevance'] ?? self::SORTS['relevance'];

        $params = [
            'gameId' => self::GAME_MINECRAFT,
            'classId' => self::CLASS_MODPACKS,
            'sortField' => $sortField,
            'sortOrder' => $sortOrder,
            'index' => $index,
            'pageSize' => $perPage,
        ];

        $query = $query !== null ? trim($query) : '';
        if ($query !== '') {
            $params['searchFilter'] = mb_substr($query, 0, 100);
        }
        if ($minecraftVersion !== null && preg_match('/^[0-9A-Za-z.\-_+ ]{1,64}$/', $minecraftVersion)) {
            $params['gameVersion'] = $minecraftVersion;
            // CurseForge only filters by loader together with a game version.
            if ($loader !== null && isset(self::LOADER_TYPES[$loader])) {
                $params['modLoaderType'] = self::LOADER_TYPES[$loader];
            }
        }

        $ids = [];
        $known = $categories !== [] ? $this->categoryIds() : [];
        foreach ($categories as $category) {
            if (isset($known[$category])) {
                $ids[] = $known[$category];
            }
        }
        if ($ids !== []) {
            $params['categoryIds'] = json_encode(array_slice(array_values(array_unique($ids)), 0, self::MAX_CATEGORY_FILTERS));
        }

        $response = $this->client->get('mods/search', $params);

        $hits = [];
        foreach (self::items($response['data'] ?? null, $perPage) as $mod) {
            if (is_array($mod) && self::intId($mod['id'] ?? null) !== null && (int) ($mod['classId'] ?? 0) === self::CLASS_MODPACKS) {
                $hits[] = $this->normaliseHit($mod);
            }
        }

        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        $total = is_numeric($pagination['totalCount'] ?? null) ? (int) $pagination['totalCount'] : count($hits);

        return ['hits' => $hits, 'total' => max(0, min(self::MAX_RESULTS, $total))];
    }

    public function categories(): array
    {
        return array_keys($this->categoryIds());
    }

    /**
     * Modpack category names => ids (cached; an unreachable API is not cached).
     *
     * @return array<string, int>
     */
    public function categoryIds(): array
    {
        // Not set up: nothing to ask (and nothing to report).
        if (!$this->client->configured()) {
            return [];
        }

        $key = 'modpacks-plus:curseforge:'.$this->client->mode().':categories';
        $cached = Cache::get($key);
        if (is_array($cached)) {
            /** @var array<string, int> $cached */
            return $cached;
        }

        try {
            $response = $this->client->get('categories', ['gameId' => self::GAME_MINECRAFT, 'classId' => self::CLASS_MODPACKS]);
        } catch (PackUnavailableException $exception) {
            report($exception);

            return [];
        }

        $categories = [];
        foreach (self::items($response['data'] ?? null, self::MAX_CATEGORIES) as $category) {
            $id = is_array($category) ? self::intId($category['id'] ?? null) : null;
            if ($id !== null && is_string($category['name'] ?? null) && empty($category['isClass'])
                && (int) ($category['classId'] ?? 0) === self::CLASS_MODPACKS && preg_match('/^[\pL\pN &\'+.,\-]{1,64}$/u', $category['name'])) {
                $categories[$category['name']] = $id;
            }
        }
        ksort($categories);

        Cache::put($key, $categories, now()->addHour());

        return $categories;
    }

    public function project(string $packId): array
    {
        $this->assertId($packId);

        return Cache::remember('modpacks-plus:curseforge:'.$this->client->mode().":project:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $mod = $this->mod($packId);

            try {
                $description = $this->client->get('mods/'.$packId.'/description');
                $body = is_string($description['data'] ?? null) ? self::htmlToText($description['data']) : '';
            } catch (PackUnavailableException) {
                $body = '';
            }

            $gallery = [];
            foreach (self::items($mod['screenshots'] ?? null, self::MAX_SCREENSHOTS) as $image) {
                $url = is_array($image) ? self::imageUrl($image['url'] ?? null) : null;
                if ($url !== null) {
                    $gallery[] = ['url' => $url, 'title' => is_string($image['title'] ?? null) ? $image['title'] : null];
                }
            }

            [$gameVersions, $loaders] = self::latestTargets($mod);

            return [
                'id' => $packId,
                'slug' => is_string($mod['slug'] ?? null) ? $mod['slug'] : $packId,
                'title' => is_string($mod['name'] ?? null) ? $mod['name'] : $packId,
                'description' => is_string($mod['summary'] ?? null) ? $mod['summary'] : '',
                'body' => $body,
                'icon_url' => self::imageUrl(is_array($mod['logo'] ?? null) ? ($mod['logo']['thumbnailUrl'] ?? null) : null),
                'gallery' => $gallery,
                'game_versions' => $gameVersions,
                'loaders' => $loaders,
                'downloads' => is_numeric($mod['downloadCount'] ?? null) ? (int) $mod['downloadCount'] : 0,
                'website_url' => self::websiteUrl($mod['links']['websiteUrl'] ?? null),
            ];
        });
    }

    public function versions(string $packId): array
    {
        $this->assertId($packId);

        return Cache::remember('modpacks-plus:curseforge:'.$this->client->mode().":versions:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $response = $this->client->get('mods/'.$packId.'/files', ['index' => 0, 'pageSize' => self::MAX_PAGE_SIZE]);

            $versions = [];
            foreach (self::items($response['data'] ?? null, self::MAX_PAGE_SIZE) as $file) {
                // Server packs are installed through their client pack, never on their own.
                if (is_array($file) && self::intId($file['id'] ?? null) !== null && (string) self::intId($file['modId'] ?? null) === $packId && empty($file['isServerPack'])) {
                    $versions[] = $this->normaliseVersion($file);
                }
            }

            usort($versions, fn (array $a, array $b) => strcmp((string) $b['date_published'], (string) $a['date_published']));

            return $versions;
        });
    }

    public function packVersion(string $packId, string $versionId): array
    {
        return $this->normaliseVersion($this->packFile($packId, $versionId));
    }

    public function fetchArchive(PackArchive $archive, string $path, PlanValidator $validator): void
    {
        // Host-agnostic: the validator's allowlist (CurseForge hosts for CurseForge plans) and the
        // archive's own size and hash decide.
        $this->modrinth->fetchArchive($archive, $path, $validator);
    }

    /**
     * Server pack first (the file's serverPackFileId, from the API only), else the client pack
     * built from manifest.json. Either way the pack's own (client) zip is read for the target:
     * its manifest names the Minecraft version and loader version, which the egg installs
     * (plan 35); a server pack's bundled start scripts and installers are never run or written.
     *
     * A server pack too large for the caps, missing, not flagged as a server pack, without a
     * download or without mods/ is skipped (the manifest build is checked the same way and
     * the summary says why). Anything hostile in it (paths, hashes) fails the install.
     */
    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan
    {
        $file = $this->packFile($packId, $versionId);
        $mod = $this->mod($packId);

        $archive = $this->archiveOf($file, $mod);
        if ($archive === null) {
            throw new PackUnavailableException('curseforge_no_download', is_string($file['fileName'] ?? null) ? $file['fileName'] : $versionId);
        }

        $version = $this->normaliseVersion($file);
        $plan = new InstallPlan(
            provider: self::ID,
            packId: $packId,
            versionId: $versionId,
            packName: is_string($mod['name'] ?? null) && $mod['name'] !== '' ? $mod['name'] : $version['name'],
            versionNumber: $version['version_number'],
            minecraftVersion: $version['game_versions'][0] ?? '',
            loader: $version['loaders'][0] ?? '',
            loaderVersion: null,
            archive: $archive,
        );

        // URL, declared size and hash format are checked before anything is downloaded.
        $validator->validate($plan);

        $path = rtrim($workDir, '/').'/'.PackArchive::WORK_FILE;
        $this->fetchArchive($archive, $path, $validator);

        $reader = PackArchiveReader::open($path, $validator, $this->caps);
        try {
            if (!$reader->has(self::MANIFEST_FILE)) {
                throw new PackUnavailableException('bad_index', 'missing '.self::MANIFEST_FILE);
            }

            $manifest = self::manifest(json_decode($reader->read(self::MANIFEST_FILE, self::MAX_MANIFEST_BYTES), true), $this->caps);
            $overrides = $reader->overrides($validator);
        } finally {
            $reader->close();
        }

        $plan = $plan->with([
            'minecraftVersion' => $manifest['minecraft'],
            'loader' => $manifest['loader'],
            'loaderVersion' => $manifest['loader_version'],
        ]);

        $fallback = null;
        $serverPackId = self::intId($file['serverPackFileId'] ?? null);
        // Through the mirror a server pack is never used: it could name any zip on the CurseForge
        // CDN, and its files carry no hash of their own. The manifest build is used instead.
        if ($serverPackId !== null && $this->client->mode() !== CurseForgeClient::MODE_KEY) {
            $fallback = ['reason' => 'server_pack_mirror', 'detail' => ''];
            $serverPackId = null;
        }
        if ($serverPackId !== null) {
            try {
                return $this->serverPackPlan($plan, $packId, $serverPackId, $validator, $workDir, $mod);
            } catch (UnsafePlanException $exception) {
                if (!in_array($exception->reason, self::SERVER_PACK_FALLBACK_REASONS, true)) {
                    throw $exception;
                }
                $fallback = ['reason' => $exception->reason, 'detail' => $exception->detail];
            } catch (PackUnavailableException $exception) {
                if ($exception->reason !== 'server_pack_unusable') {
                    throw $exception;
                }
                $fallback = ['reason' => $exception->reason, 'detail' => $exception->detail];
            }
            @unlink(rtrim($workDir, '/').'/'.PackArchive::SERVER_PACK_WORK_FILE);
        }

        return $this->manifestPlan($plan->with(['overrides' => $overrides, 'serverPackFallback' => $fallback]), $manifest['files'], $validator);
    }

    /**
     * The server pack as the plan's override source: every file under its root folder is
     * written like an override (same path, protected-path, client-only, size and metadata
     * rules), its start scripts, installers and bundled Java are skipped.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException `server_pack_unusable` when the manifest build should be used instead
     */
    /** @param  array<mixed>  $mod  the pack */
    private function serverPackPlan(InstallPlan $plan, string $packId, int $serverPackId, PlanValidator $validator, string $workDir, array $mod): InstallPlan
    {
        try {
            $response = $this->client->get('mods/'.$packId.'/files/'.$serverPackId);
        } catch (PackUnavailableException $exception) {
            if ($exception->reason !== 'curseforge_not_found') {
                throw $exception;
            }

            throw new PackUnavailableException('server_pack_unusable', (string) $serverPackId);
        }

        $file = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (self::intId($file['id'] ?? null) !== $serverPackId || (string) self::intId($file['modId'] ?? null) !== $packId || empty($file['isServerPack'])) {
            throw new PackUnavailableException('server_pack_unusable', (string) $serverPackId);
        }

        $serverPack = $this->archiveOf($file, $mod) ?? throw new PackUnavailableException('server_pack_unusable', (string) $serverPackId);

        // Size and hash format first, then download (size and sha1 checked) and read safely.
        $plan = $validator->validate($plan->with(['serverPack' => $serverPack, 'serverPackRoot' => '']));

        $path = rtrim($workDir, '/').'/'.PackArchive::SERVER_PACK_WORK_FILE;
        $this->fetchArchive($serverPack, $path, $validator);

        $reader = PackArchiveReader::open($path, $validator, $this->caps);
        try {
            $root = self::serverPackRoot($reader->names());
            $overrides = [];
            foreach ($reader->names() as $name) {
                $target = $validator->overrideTarget($name, $root);
                if ($target !== null) {
                    $overrides[] = new OverrideEntry($name, $target, (int) $reader->size($name));
                }
            }
        } finally {
            $reader->close();
        }

        if (!array_filter($overrides, fn (OverrideEntry $entry) => str_starts_with($entry->path, 'mods/'))) {
            throw new PackUnavailableException('server_pack_unusable', 'no mods/');
        }

        return $validator->validate($plan->with(['serverPackRoot' => $root, 'overrides' => $overrides, 'files' => []]));
    }

    /**
     * The folder a server pack keeps its files in: its only top-level folder when everything
     * is inside one (a common `PackName-Server/` wrapper), else the zip root.
     *
     * @param  array<int, string>  $names
     */
    public static function serverPackRoot(array $names): string
    {
        $tops = [];
        foreach ($names as $name) {
            $name = rtrim($name, '/');
            if (!str_contains($name, '/') && !in_array($name.'/', $names, true)) {
                return ''; // a file at the zip root
            }
            $tops[explode('/', $name)[0]] = true;
        }

        if (count($tops) !== 1) {
            return '';
        }

        $top = (string) array_key_first($tops);

        return in_array(strtolower($top), self::SERVER_FOLDERS, true) ? '' : $top.'/';
    }

    /**
     * The client pack: manifest files resolved in batches and placed by class, files
     * CurseForge may not hand out (or that belong in a world) listed for manual download.
     *
     * @param  array<int, array{project: int, file: int}>  $entries
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function manifestPlan(InstallPlan $plan, array $entries, PlanValidator $validator): InstallPlan
    {
        $files = $this->resolveFiles(array_column($entries, 'file'));
        $mods = $this->resolveMods(array_values(array_unique(array_column($entries, 'project'))));

        $candidates = [];
        $manual = [];
        foreach ($entries as $entry) {
            $file = $files[$entry['file']] ?? null;
            $mod = $mods[$entry['project']] ?? null;
            if ($file === null || $mod === null || self::intId($file['modId'] ?? null) !== $entry['project']) {
                throw new PackUnavailableException('curseforge_not_found', $entry['project'].'/'.$entry['file']);
            }

            $name = is_string($file['fileName'] ?? null) ? $file['fileName'] : '';
            $classId = is_int($mod['classId'] ?? null) ? $mod['classId'] : 0;
            // Placed files must be plain .jar/.zip names (data packs and other kinds are only listed).
            if (!self::isSafeFileName($name) || (isset(self::CLASS_FOLDERS[$classId]) && !self::hasExtension($name, self::FILE_EXTENSIONS))) {
                throw new UnsafePlanException('unsafe_path', $name);
            }

            $display = is_string($file['displayName'] ?? null) && $file['displayName'] !== '' ? $file['displayName'] : $name;
            $page = self::websiteUrl($mod['links']['websiteUrl'] ?? null);
            $link = $page !== null ? $page.'/files/'.$entry['file'] : null;

            $folder = self::CLASS_FOLDERS[$classId] ?? null;
            if ($folder === null) {
                $manual[] = ['name' => $display, 'url' => $link, 'reason' => $classId === self::CLASS_DATAPACKS ? 'datapack' : 'unsupported_type'];

                continue;
            }

            $url = $this->fileUrl($file, $mod, $entry['file'], $name);
            $candidates[] = [
                'path' => $folder.'/'.$name,
                'urls' => $url !== null ? [$url] : [],
                'size' => is_int($file['fileLength'] ?? null) ? $file['fileLength'] : -1,
                'hashes' => self::hashes($file),
                // Resource and shader packs only matter to game clients.
                'side' => in_array($classId, self::CLIENT_CLASSES, true) ? 'unsupported' : 'unknown',
                'manual' => ['name' => $display, 'url' => $link, 'reason' => 'distribution_disabled'],
            ];
        }

        // Modrinth-first: a file Modrinth has (same sha1, same size) is downloaded from
        // cdn.modrinth.com and checked against Modrinth's sha512, which also installs files
        // CurseForge may not hand out; its server_side drops client-only mods. Unknown stays in.
        $matches = $this->matcher->match(array_values(array_filter(array_map(fn (array $candidate) => $candidate['hashes']['sha1'] ?? null, $candidates))));

        $planFiles = [];
        $clientOnly = [];
        foreach ($candidates as $candidate) {
            $match = $matches[$candidate['hashes']['sha1'] ?? ''] ?? null;
            $onModrinth = $match !== null && $match['size'] === $candidate['size'];
            if ($onModrinth) {
                $candidate['urls'] = array_merge([$match['url']], $candidate['urls']);
                $candidate['hashes'] = ['sha512' => $match['sha512']] + $candidate['hashes'];
                if ($candidate['side'] !== 'unsupported') {
                    $candidate['side'] = $match['server_side'];
                    if ($candidate['side'] === 'unsupported') {
                        // Said so by Modrinth: the summary names the project that decided it.
                        $clientOnly[] = ['path' => $candidate['path'], 'project' => $match['project_slug'] ?? $match['project_id']];
                    }
                }
            }

            if ($candidate['urls'] === [] && $candidate['side'] !== 'unsupported') {
                $manual[] = $candidate['manual'];

                continue;
            }

            // Only on CurseForge and too big for the panel to fetch and hash: the user downloads it.
            if (!$onModrinth && $candidate['side'] !== 'unsupported' && $candidate['size'] > $this->caps->maxPanelFetchBytes) {
                $manual[] = ['reason' => 'too_large'] + $candidate['manual'];

                continue;
            }

            $planFiles[] = PlanFile::fromArray($candidate);
        }

        $plan = $validator->validate($plan->with(['files' => $planFiles, 'manualFiles' => $manual, 'clientOnlyBy' => $clientOnly]));

        // Files on cdn.modrinth.com whose sha512 Modrinth vouches for at that URL may be pulled by
        // the node; everything else is fetched, redirect-checked and hashed by the panel.
        return $validator->validate($this->modrinth->assignVerification($plan));
    }

    /**
     * Where the panel may download a pack file from CurseForge, or null when it must not:
     * never when the author turned third-party downloads off (allowModDistribution false),
     * else the API's downloadUrl (rebuilt), else the edge.forgecdn.net URL CurseForge documents
     * for an id and name, and only when the author explicitly allows distribution.
     *
     * @param  array<mixed>  $file
     * @param  array<mixed>  $mod
     */
    private function fileUrl(array $file, array $mod, int $fileId, string $name): ?string
    {
        $allowed = $mod['allowModDistribution'] ?? null;
        if ($allowed === false) {
            return null;
        }

        $downloadUrl = $file['downloadUrl'] ?? null;
        if ($downloadUrl === null) {
            // No downloadUrl: only when the author explicitly allows distribution.
            return $allowed === true ? self::constructedUrl($fileId, $name) : null;
        }

        return self::fileDownloadUrl($fileId, $name, $downloadUrl);
    }

    /**
     * The URL a CurseForge file is fetched from: always the edge.forgecdn.net path built from the
     * file's own id and name (as CurseForge documents). The API's downloadUrl is never used as
     * such; it must name exactly that path (on edge or mediafilez), else the file gets no URL.
     *
     * Threat: an API answer (above all the third-party mirror's) pointing a file at other bytes
     * that carry a matching hash it also supplies. With the URL built from the id, the bytes are
     * whatever CurseForge's own CDN serves for that file; the hash is a consistency check on top.
     */
    public static function fileDownloadUrl(int $fileId, string $fileName, mixed $downloadUrl): ?string
    {
        $constructed = self::constructedUrl($fileId, $fileName);
        $given = self::downloadUrl($downloadUrl);
        if ($constructed === null || $given === null) {
            return null;
        }

        return parse_url($given, PHP_URL_PATH) === parse_url($constructed, PHP_URL_PATH) ? $constructed : null;
    }

    /** @param  array<int, string>  $extensions */
    public static function hasExtension(string $name, array $extensions): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true);
    }

    /**
     * Files by id, fetched in batches.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<mixed>>
     *
     * @throws PackUnavailableException
     */
    private function resolveFiles(array $ids): array
    {
        $files = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::RESOLVE_CHUNK) as $chunk) {
            $response = $this->client->post('mods/files', ['fileIds' => $chunk]);
            foreach (self::items($response['data'] ?? null, 2 * self::RESOLVE_CHUNK) as $file) {
                $id = is_array($file) ? self::intId($file['id'] ?? null) : null;
                if ($id !== null && in_array($id, $chunk, true)) {
                    $files[$id] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * Mods (projects) by id, fetched in batches.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<mixed>>
     *
     * @throws PackUnavailableException
     */
    private function resolveMods(array $ids): array
    {
        $mods = [];
        foreach (array_chunk($ids, self::RESOLVE_CHUNK) as $chunk) {
            $response = $this->client->post('mods', ['modIds' => $chunk]);
            foreach (self::items($response['data'] ?? null, 2 * self::RESOLVE_CHUNK) as $mod) {
                $id = is_array($mod) ? self::intId($mod['id'] ?? null) : null;
                if ($id !== null && in_array($id, $chunk, true) && (int) ($mod['gameId'] ?? 0) === self::GAME_MINECRAFT) {
                    $mods[$id] = $mod;
                }
            }
        }

        return $mods;
    }

    /**
     * The parts of a CurseForge manifest.json the install uses, strictly checked.
     * Optional files (`required: false`) are left out, as the CurseForge app does by default.
     *
     * @return array{minecraft: string, loader: string, loader_version: string, files: array<int, array{project: int, file: int}>}
     *
     * @throws PackUnavailableException
     * @throws UnsafePlanException
     */
    public static function manifest(mixed $manifest, Caps $caps): array
    {
        if (!is_array($manifest) || ($manifest['manifestType'] ?? null) !== 'minecraftModpack' || ($manifest['manifestVersion'] ?? null) !== 1) {
            throw new PackUnavailableException('bad_index', self::MANIFEST_FILE);
        }

        $minecraft = is_array($manifest['minecraft'] ?? null) ? $manifest['minecraft'] : [];
        $version = $minecraft['version'] ?? null;
        if (!is_string($version) || !preg_match('/^[A-Za-z0-9.\-_+]{1,64}$/', $version)) {
            throw new PackUnavailableException('bad_index', 'minecraft.version');
        }

        $loaders = array_values(array_filter(is_array($minecraft['modLoaders'] ?? null) ? $minecraft['modLoaders'] : [], 'is_array'));
        $primary = array_values(array_filter($loaders, fn (array $loader) => ($loader['primary'] ?? false) === true));
        $chosen = count($primary) === 1 ? $primary[0] : (count($loaders) === 1 ? $loaders[0] : null);
        if ($chosen === null || !is_string($chosen['id'] ?? null)
            || !preg_match('/^(forge|neoforge|fabric|quilt)-([0-9A-Za-z.+\-_]{1,64})$/', $chosen['id'], $match)) {
            throw new PackUnavailableException('bad_index', 'minecraft.modLoaders');
        }

        if (($manifest['overrides'] ?? 'overrides') !== 'overrides') {
            throw new PackUnavailableException('bad_index', 'overrides');
        }

        $list = $manifest['files'] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            throw new PackUnavailableException('bad_index', 'files');
        }
        if (count($list) > $caps->maxFiles) {
            throw new UnsafePlanException('too_many_files', (string) count($list));
        }

        $files = [];
        $seen = [];
        foreach ($list as $entry) {
            $project = is_array($entry) ? self::intId($entry['projectID'] ?? null) : null;
            $file = is_array($entry) ? self::intId($entry['fileID'] ?? null) : null;
            if ($project === null || $file === null) {
                throw new PackUnavailableException('bad_index', 'files');
            }
            if (($entry['required'] ?? true) === false || isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $files[] = ['project' => $project, 'file' => $file];
        }

        return ['minecraft' => $version, 'loader' => $match[1], 'loader_version' => $match[2], 'files' => $files];
    }

    /**
     * The pack's own file $fileId (a client pack, not a server pack).
     *
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    protected function packFile(string $packId, string $fileId): array
    {
        $this->assertId($packId);
        $this->assertId($fileId);

        $file = $this->client->get('mods/'.$packId.'/files/'.$fileId);
        $file = is_array($file['data'] ?? null) ? $file['data'] : null;

        if ($file === null || (string) self::intId($file['id'] ?? null) !== $fileId) {
            throw new PackUnavailableException('curseforge_not_found', $packId.'/'.$fileId);
        }
        if ((string) self::intId($file['modId'] ?? null) !== $packId) {
            throw new PackUnavailableException('version_mismatch', $fileId);
        }
        if (!empty($file['isServerPack'])) {
            throw new PackUnavailableException('curseforge_not_found', $packId.'/'.$fileId);
        }

        return $file;
    }

    /**
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    protected function mod(string $packId): array
    {
        $response = $this->client->get('mods/'.$packId);
        $mod = is_array($response['data'] ?? null) ? $response['data'] : null;

        if ($mod === null || (string) self::intId($mod['id'] ?? null) !== $packId) {
            throw new PackUnavailableException('curseforge_not_found', $packId);
        }
        if ((int) ($mod['gameId'] ?? 0) !== self::GAME_MINECRAFT || (int) ($mod['classId'] ?? 0) !== self::CLASS_MODPACKS) {
            throw new PackUnavailableException('not_a_modpack', $packId);
        }

        return $mod;
    }

    /**
     * A CurseForge download URL rebuilt from its parts, or null when it is anything but
     * https://(edge|mediafilez).forgecdn.net/files/<n>/<n>/<file name>.
     */
    public static function downloadUrl(mixed $url): ?string
    {
        if (!is_string($url) || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', self::DOWNLOAD_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_starts_with($url, 'https://'.$parts['host'].'/')) {
            return null;
        }

        if (!preg_match('#^/files/([0-9]{1,7})/([0-9]{1,3})/([^/]+)$#', $parts['path'] ?? '', $match)) {
            return null;
        }

        $name = rawurldecode($match[3]);

        return self::isSafeFileName($name) ? 'https://'.$parts['host'].'/files/'.$match[1].'/'.$match[2].'/'.rawurlencode($name) : null;
    }

    /** The edge.forgecdn.net URL CurseForge documents for a file id and name. */
    public static function constructedUrl(int $fileId, string $fileName): ?string
    {
        if ($fileId <= 0 || $fileId > 2147483647 || !self::isSafeFileName($fileName)) {
            return null;
        }

        return 'https://edge.forgecdn.net/files/'.intdiv($fileId, 1000).'/'.($fileId % 1000).'/'.rawurlencode($fileName);
    }

    /** One plain file name: no separators, parents, control characters or percent-encoding. */
    public static function isSafeFileName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 255 && !str_contains($name, '/') && !str_starts_with($name, '.')
            && PlanValidator::isSafeRelativePath($name);
    }

    /** A positive integer id from JSON (int or numeric string), else null. */
    public static function intId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 2147483647 ? $value : null;
        }

        return is_string($value) && self::isValidId($value) ? (int) $value : null;
    }

    /**
     * Minecraft versions and loaders a CurseForge file is for (from its gameVersions).
     *
     * @param  array<mixed>  $gameVersions
     * @return array{array<int, string>, array<int, string>}
     */
    public static function targets(array $gameVersions): array
    {
        $minecraft = [];
        $loaders = [];
        foreach ($gameVersions as $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match('/^[0-9]{1,3}\.[0-9]{1,3}(\.[0-9]{1,3})?$/', $value)) {
                $minecraft[] = $value;
            } elseif (isset(self::LOADER_TYPES[strtolower($value)])) {
                $loaders[] = strtolower($value);
            }
        }

        return [array_values(array_unique($minecraft)), array_values(array_unique($loaders))];
    }

    /**
     * @param  array<mixed>  $mod
     * @return array{array<int, string>, array<int, string>}
     */
    private static function latestTargets(array $mod): array
    {
        $minecraft = [];
        $loaders = [];
        $types = array_flip(self::LOADER_TYPES);
        foreach (self::items($mod['latestFilesIndexes'] ?? null, self::MAX_SMALL_LIST) as $index) {
            if (!is_array($index)) {
                continue;
            }
            [$versions] = self::targets([$index['gameVersion'] ?? null]);
            array_push($minecraft, ...$versions);
            if (is_int($index['modLoader'] ?? null) && isset($types[$index['modLoader']])) {
                $loaders[] = $types[$index['modLoader']];
            }
        }

        return [array_values(array_unique($minecraft)), array_values(array_unique($loaders))];
    }

    /**
     * @param  array<mixed>  $mod
     * @return PackHit
     */
    private function normaliseHit(array $mod): array
    {
        $id = (string) self::intId($mod['id']);
        $authors = is_array($mod['authors'] ?? null) ? $mod['authors'] : [];
        $categories = [];
        foreach (self::items($mod['categories'] ?? null, self::MAX_SMALL_LIST) as $category) {
            if (is_array($category) && is_string($category['name'] ?? null)) {
                $categories[] = $category['name'];
            }
        }

        return [
            'id' => $id,
            'slug' => is_string($mod['slug'] ?? null) ? $mod['slug'] : $id,
            'title' => is_string($mod['name'] ?? null) ? $mod['name'] : $id,
            'description' => is_string($mod['summary'] ?? null) ? $mod['summary'] : '',
            'author' => is_array($authors[0] ?? null) && is_string($authors[0]['name'] ?? null) ? $authors[0]['name'] : '',
            'icon_url' => self::imageUrl(is_array($mod['logo'] ?? null) ? ($mod['logo']['thumbnailUrl'] ?? null) : null),
            'downloads' => is_numeric($mod['downloadCount'] ?? null) ? (int) $mod['downloadCount'] : 0,
            'follows' => is_numeric($mod['thumbsUpCount'] ?? null) ? (int) $mod['thumbsUpCount'] : 0,
            'date_modified' => is_string($mod['dateModified'] ?? null) ? $mod['dateModified'] : null,
            'categories' => $categories,
            'game_versions' => self::latestTargets($mod)[0],
        ];
    }

    /**
     * @param  array<mixed>  $file
     * @return PackVersion
     */
    protected function normaliseVersion(array $file): array
    {
        [$gameVersions, $loaders] = self::targets(is_array($file['gameVersions'] ?? null) ? $file['gameVersions'] : []);
        $archive = $this->archiveOf($file);
        $name = is_string($file['displayName'] ?? null) && $file['displayName'] !== '' ? $file['displayName'] : (is_string($file['fileName'] ?? null) ? $file['fileName'] : '');

        return [
            'id' => (string) self::intId($file['id'] ?? null),
            'project_id' => (string) self::intId($file['modId'] ?? null),
            'name' => $name,
            'version_number' => $name,
            'version_type' => self::RELEASE_TYPES[is_int($file['releaseType'] ?? null) ? $file['releaseType'] : 1] ?? 'release',
            'game_versions' => $gameVersions,
            'loaders' => $loaders,
            'date_published' => is_string($file['fileDate'] ?? null) ? $file['fileDate'] : null,
            'downloads' => is_numeric($file['downloadCount'] ?? null) ? (int) $file['downloadCount'] : 0,
            'changelog' => null,
            'file' => $archive?->toArray(),
        ];
    }

    /**
     * A pack (or server pack) file as an archive: its CurseForge download URL (rebuilt), size
     * and sha1. Null when CurseForge offers no download (the author turned third-party
     * downloads off) or the file name is unsafe.
     *
     * @param  array<mixed>  $file
     * @param  array<mixed>|null  $mod  the pack: when its author turned third-party downloads off, nothing is fetched
     */
    protected function archiveOf(array $file, ?array $mod = null): ?PackArchive
    {
        if ($mod !== null && ($mod['allowModDistribution'] ?? null) === false) {
            return null;
        }

        $id = self::intId($file['id'] ?? null);
        $name = is_string($file['fileName'] ?? null) ? $file['fileName'] : '';
        $downloadUrl = $file['downloadUrl'] ?? null;
        // A pack zip is only fetched when CurseForge offers it (a downloadUrl), and then from the
        // URL built from its own id and name, never from the downloadUrl itself.
        $url = $id !== null && $downloadUrl !== null && self::hasExtension($name, self::ARCHIVE_EXTENSIONS) ? self::fileDownloadUrl($id, $name, $downloadUrl) : null;
        if ($url === null) {
            return null;
        }

        return new PackArchive($url, $name, is_int($file['fileLength'] ?? null) ? $file['fileLength'] : -1, self::hashes($file));
    }

    /**
     * The file's sha1 (CurseForge HashAlgo 1). md5 is ignored: it is not used to verify anything.
     *
     * @param  array<mixed>  $file
     * @return array<string, string>
     */
    public static function hashes(array $file): array
    {
        foreach (self::items($file['hashes'] ?? null, self::MAX_SMALL_LIST) as $hash) {
            if (is_array($hash) && ($hash['algo'] ?? null) === self::HASH_SHA1 && is_string($hash['value'] ?? null)) {
                return ['sha1' => strtolower($hash['value'])];
            }
        }

        return [];
    }

    /**
     * At most $max entries of an API list (anything else is no entries): a hostile answer can't
     * make a request loop over more than was asked for.
     *
     * @return array<int, mixed>
     */
    private static function items(mixed $list, int $max): array
    {
        return is_array($list) ? array_slice(array_values($list), 0, max(0, $max)) : [];
    }

    /** CurseForge HTML descriptions as plain text (the page renders it as escaped markdown). */
    public static function htmlToText(string $html): string
    {
        $html = mb_substr($html, 0, self::MAX_BODY_CHARS * 4);
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|h[1-6]|li|tr|ul|ol)\s*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return mb_substr(trim($text), 0, self::MAX_BODY_CHARS);
    }

    private static function imageUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= 2048 && !preg_match('/[\s"\'<>\\\\]/', $url) ? $url : null;
    }

    /** A link to the file or project on the CurseForge website (shown to users, never fetched). */
    public static function websiteUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https://www\.curseforge\.com/minecraft/[A-Za-z0-9/_.\-]{1,200}$#', $url) && !str_contains($url, '..') ? $url : null;
    }

    /** @throws PackUnavailableException */
    private function assertId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new PackUnavailableException('curseforge_not_found', $id);
        }
    }
}
