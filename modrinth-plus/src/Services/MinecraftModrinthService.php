<?php

namespace DrMxrcy\ModrinthPlus\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Enums\ModrinthSearchIndex;
use DrMxrcy\ModrinthPlus\Support\SearchFacets;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MinecraftModrinthService
{
    // Seconds to wait for the daemon while it downloads a file for us.
    protected const DOWNLOAD_TIMEOUT = 120;

    // Version list cache TTL (minutes); kept short so a transient empty Modrinth response can't hide updates for long.
    protected const VERSIONS_CACHE_MINUTES = 30;

    protected const EMPTY_VERSIONS_CACHE_MINUTES = 5;

    protected const MODRINTH_API_URL = 'https://api.modrinth.com/v2';

    /** Modrinth categories never offered as a filter (joke content, not useful on a server). */
    protected const HIDDEN_CATEGORIES = ['cursed'];

    /** "Scan folder" skips jars larger than this (bytes) instead of pulling them through the panel. */
    public const SCAN_MAX_FILE_BYTES = 50 * 1024 * 1024;

    protected static ?string $pluginVersion = null;

    /**
     * Base request for every Modrinth API call: base URL, the unique User-Agent
     * Modrinth asks API clients to send, JSON and timeouts.
     */
    protected function modrinth(int $timeout = 5): PendingRequest
    {
        return $this->configureModrinthRequest(Http::createPendingRequest(), $timeout);
    }

    /** Applies the shared Modrinth settings to an existing request (e.g. one inside Http::pool). */
    protected function configureModrinthRequest(PendingRequest $request, int $timeout = 5): PendingRequest
    {
        return $request
            ->baseUrl(self::MODRINTH_API_URL)
            ->withUserAgent($this->modrinthUserAgent())
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout(5);
    }

    protected function modrinthUserAgent(): string
    {
        return 'DrMxrcy/pelican-plugins-modrinth-plus/'.self::pluginVersion().' (github.com/DrMxrcy/pelican-plugins)';
    }

    protected static function pluginVersion(): string
    {
        if (self::$pluginVersion === null) {
            $manifest = dirname(__DIR__, 2).'/plugin.json';
            $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

            self::$pluginVersion = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : 'unknown';
        }

        return self::$pluginVersion;
    }

    public function getMinecraftVersion(Server $server): ?string
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        // Proxies are not tied to a specific minecraft version
        if (in_array('proxy', $tags)) {
            return null;
        }

        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return $this->getLatestMinecraftVersion();
        }

        return $version;
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth:latest_minecraft_version', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = $this->modrinth(5)
                    ->throw()
                    ->get('tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /** @return array{icon: string, name: string, supported_project_types: string[], display_name: string}|null */
    public function getLoaderFromServer(Server $server): ?array
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        if (!in_array('minecraft', $tags)) {
            return null;
        }

        $projectTypes = array_map(fn (ModrinthProjectType $projectType) => $projectType->value, ModrinthProjectType::fromServer($server));
        if (empty($projectTypes)) {
            return null;
        }

        $loaders = $this->getLoaders();
        foreach ($loaders as $loader) {
            if (!array_intersect($loader['supported_project_types'], $projectTypes)) {
                continue;
            }

            if (in_array($loader['name'], $tags)) {
                return array_merge($loader, ['display_name' => str($loader['name'])->title()->toString()]);
            }
        }

        return null;
    }

    /** @return array<int, array{icon: string, name: string, supported_project_types: string[]}> */
    public function getLoaders(): array
    {
        return cache()->remember('modrinth:loaders', now()->addHour(), function () {
            try {
                return $this->modrinth(5)
                    ->throw()
                    ->get('tag/loader')
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /**
     * Category names Modrinth offers for this project type, sorted. Modrinth's whole tag list is
     * cached for an hour and shared by every page; a failed fetch is not cached (and yields none),
     * so the filter comes back on the next render once Modrinth answers again.
     *
     * Ruling: "cursed" is left out of the filter on purpose.
     *
     * @return array<int, string>
     */
    public function getCategories(ModrinthProjectType $modrinthProjectType): array
    {
        $tags = cache()->get('modrinth:categories');

        if (!is_array($tags)) {
            try {
                $tags = $this->modrinth(5)
                    ->throw()
                    ->get('tag/category')
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }

            if (!is_array($tags)) {
                return [];
            }

            cache()->put('modrinth:categories', $tags, now()->addHour());
        }

        $names = [];
        foreach ($tags as $tag) {
            if (
                is_array($tag)
                && ($tag['project_type'] ?? null) === $modrinthProjectType->value
                && is_string($tag['name'] ?? null)
                && $tag['name'] !== ''
                && !in_array($tag['name'], self::HIDDEN_CATEGORIES, true)
            ) {
                $names[] = $tag['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Search results are narrowed to projects with at least one build for both the server's loader
     * and Minecraft version (loader only on proxies): search facets match per project, so a hit can
     * satisfy them with two different builds. A hit whose version lookup failed is kept, so a
     * Modrinth outage never empties the list. `versions` holds the lookup per kept hit (list, or
     * null when it failed) so callers can reuse it instead of fetching again.
     *
     * Pagination ruling: `total_hits` is Modrinth's count and is not corrected, and a page is not
     * refilled from the next one, so a page may show fewer than 20 rows.
     *
     * @param  bool  $includeClientOnly  also list client-only projects, overriding the hide_client_only config
     * @param  ModrinthSearchIndex|null  $index  sort order; null picks relevance with a search term, else downloads
     * @param  array<mixed>  $categories  Modrinth category names, any of which must match
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int, versions: array<string, array<int, mixed>|null>}
     */
    public function getProjects(Server $server, ModrinthProjectType $modrinthProjectType, int $page = 1, ?string $search = null, bool $includeClientOnly = false, ?ModrinthSearchIndex $index = null, array $categories = []): array
    {
        $modrinthProjectType = $modrinthProjectType->value;
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
                'versions' => [],
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $hideClientOnly = !$includeClientOnly && (bool) config('modrinth-plus.hide_client_only', true);

        $index ??= ModrinthSearchIndex::defaultFor($search);
        $categories = SearchFacets::normalizeCategories($categories);

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'index' => $index->value,
            'facets' => SearchFacets::build($minecraftLoader, $minecraftVersion, $hideClientOnly, categories: $categories),
        ];

        // Every filter is part of the key so changing one never serves another variant's cached page.
        $key = "modrinth_projects:{$modrinthProjectType}:$minecraftVersion:$minecraftLoader:".($hideClientOnly ? 'server' : 'all').":$page:{$index->value}";

        if ($categories !== []) {
            $key .= ':cat:'.md5(implode(',', $categories));
        }

        if ($search) {
            $data['query'] = $search;

            $key .= ':'.md5($search);
        }

        // The raw search page is cached; the compatibility filter runs on every call against the
        // (separately cached) version lists, so a failed lookup is retried on the next render.
        $response = cache()->get($key);

        if (!is_array($response)) {
            try {
                $response = $this->modrinth(5)
                    ->throw()
                    ->get('search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                // not caching on purpose since caching a failure would keep the page empty for 30 minutes
                return [
                    'hits' => [],
                    'total_hits' => 0,
                    'versions' => [],
                ];
            }

            cache()->put($key, $response, now()->addMinutes(30));
        }

        return $this->onlyCompatibleHits($response, $minecraftVersion, $minecraftLoader);
    }

    /**
     * @param  array<mixed>  $response  raw Modrinth search response
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int, versions: array<string, array<int, mixed>|null>}
     */
    protected function onlyCompatibleHits(array $response, ?string $minecraftVersion, string $minecraftLoader): array
    {
        /** @var array<int, array<string, mixed>> $hits */
        $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];

        $projectIds = array_values(array_filter(array_map(fn (array $hit) => is_string($hit['project_id'] ?? null) ? $hit['project_id'] : null, $hits)));

        $versions = $this->fetchProjectVersions($projectIds, $minecraftVersion, $minecraftLoader);

        // Only Modrinth positively saying "no build" ([]) hides a hit; failures (null) and hits we
        // can't look up stay visible.
        $hits = array_values(array_filter($hits, fn (array $hit) => !is_string($hit['project_id'] ?? null) || ($versions[$hit['project_id']] ?? null) !== []));

        return [
            'hits' => $hits,
            'total_hits' => (int) ($response['total_hits'] ?? 0),
            // kept hits only, so the page can reuse them without another round trip
            'versions' => array_filter($versions, fn (?array $list) => $list !== []),
        ];
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $installedModsById = [];
        foreach ($installedMods as $mod) {
            if (!isset($installedModsById[$mod['project_id']])) {
                $installedModsById[$mod['project_id']] = $mod;
            }
        }

        $projectIds = array_keys($installedModsById);

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = json_encode($pageIds, JSON_THROW_ON_ERROR);
        $cacheKey = 'modrinth_bulk:'.md5($idsParam);

        $modrinthProjects = cache()->get($cacheKey);

        if (!is_array($modrinthProjects)) {
            try {
                $modrinthProjects = $this->modrinth(10)
                    ->throw()
                    ->get('projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();

                if (!is_array($modrinthProjects)) {
                    $modrinthProjects = [];
                }

                cache()->put($cacheKey, $modrinthProjects, now()->addMinutes(30));
            } catch (Exception $exception) {
                report($exception);

                $modrinthProjects = [];
            }
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = $installedModsById[$projectId];

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('modrinth-plus::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    protected function getVersionsCacheKey(string $projectId, ?string $minecraftVersion, string $minecraftLoader): string
    {
        return "modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader";
    }

    /** @return array<string, string> */
    protected function getVersionsQuery(?string $minecraftVersion, string $minecraftLoader): array
    {
        $query = [
            'loaders' => '["' . $minecraftLoader . '"]',
        ];

        if ($minecraftVersion) {
            $query['game_versions'] = '["' . $minecraftVersion . '"]';
        }

        return $query;
    }

    /** @param  array<int, mixed>  $versions */
    protected function cacheVersions(string $key, array $versions): void
    {
        cache()->put($key, $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));
    }

    /**
     * @param  array<int, mixed>  $versions
     * @return array<int, mixed>
     */
    protected function sortVersions(array $versions): array
    {
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /** @return array<array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: string[], version_type: string, loaders: string[], featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}> */
    public function getProjectVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $key = $this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader);

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $versions = $this->modrinth(5)
                ->throw()
                ->get("project/$projectId/version", $this->getVersionsQuery($minecraftVersion, $minecraftLoader))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not cached on purpose since an empty list would hide the update action for 30 minutes.
            return [];
        }

        $versions = is_array($versions) ? $this->sortVersions($versions) : [];

        $this->cacheVersions($key, $versions);

        return $versions;
    }

    /**
     * fetch compatible versions of several projects at once, a full installed
     * tab used to cost up to 20 sequential Modrinth round trips whilst this fetches in bulk.
     *
     * A project maps to its compatible versions (newest first), to `[]` when Modrinth says it has
     * none for this loader/version, or to `null` when the request failed (error status, timeout,
     * connection error). Failures are never cached, so callers can treat `null` as "unknown" and
     * must not mistake an outage for incompatibility.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>|null>
     */
    public function getProjectVersionsBulk(array $projectIds, Server $server): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if (empty($projectIds)) {
            return [];
        }

        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return array_fill_keys($projectIds, []);
        }

        return $this->fetchProjectVersions($projectIds, $this->getMinecraftVersion($server), $minecraftLoader['name']);
    }

    /**
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>|null> see getProjectVersionsBulk()
     */
    protected function fetchProjectVersions(array $projectIds, ?string $minecraftVersion, string $minecraftLoader): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if (empty($projectIds)) {
            return [];
        }

        $results = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader));

            if (is_array($cached)) {
                $results[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $projectId) => $this->configureModrinthRequest($pool->as($projectId), 10)
                    ->get("project/$projectId/version", $this->getVersionsQuery($minecraftVersion, $minecraftLoader)),
                $missing
            ));
        } catch (Exception $exception) {
            report($exception);

            $responses = [];
        }

        foreach ($missing as $projectId) {
            $response = $responses[$projectId] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $versions = $response->json();
                $versions = is_array($versions) ? $this->sortVersions($versions) : [];

                $this->cacheVersions($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader), $versions);

                $results[$projectId] = $versions;

                continue;
            }

            if ($response instanceof Exception) {
                report($response);
            }

            // not cached on purpose: a failure must stay distinguishable from "no compatible version".
            $results[$projectId] = null;
        }

        return $results;
    }

    /**
     * Modrinth projects by id (`/v2/projects`), keyed by id. Ids Modrinth doesn't know, and every
     * id when the request fails, are missing from the result.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<string, mixed>>
     */
    public function getProjectsByIds(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if (empty($projectIds)) {
            return [];
        }

        $idsParam = json_encode($projectIds, JSON_THROW_ON_ERROR);
        $cacheKey = 'modrinth_projects_by_id:'.md5($idsParam);

        $projects = cache()->get($cacheKey);

        if (!is_array($projects)) {
            try {
                $projects = $this->modrinth(10)
                    ->throw()
                    ->get('projects', ['ids' => $idsParam])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }

            $projects = is_array($projects) ? $projects : [];

            cache()->put($cacheKey, $projects, now()->addMinutes(30));
        }

        $result = [];
        foreach ($projects as $project) {
            if (is_array($project) && is_string($project['id'] ?? null)) {
                $result[$project['id']] = $project;
            }
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(ModrinthProjectType $modrinthProjectType): string
    {
        return join_paths($modrinthProjectType->getFolder(), '.modrinth-metadata.json');
    }

    /**
     * Installed mods from the metadata file; [] when there is none yet and also when it could not
     * be read. Use readInstalledModsMetadata() to tell those two apart.
     *
     * @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>
     */
    public function getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        return $this->readInstalledModsMetadata($server, $modrinthProjectType) ?? [];
    }

    /**
     * Like getInstalledModsMetadata(), but null when the file exists yet could not be read (daemon
     * unreachable, timeout), so callers that act on "not installed" can stop instead of guessing.
     * A missing file is [] (nothing installed); an unparsable one is [] too, as it gets rewritten.
     *
     * @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null
     */
    public function readInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): ?array
    {
        try {
            $fileRepository = app(DaemonFileRepository::class);

            $metadataPath = $this->getMetadataFilePath($modrinthProjectType);
            $content = $fileRepository->setServer($server)->getContent($metadataPath);
            $metadata = json_decode($content, true);
        } catch (FileNotFoundException) {
            return [];
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
            return [];
        }

        $requiredKeysFlipped = array_flip([
            'project_id',
            'project_slug',
            'project_title',
            'version_id',
            'version_number',
            'filename',
            'installed_at',
        ]);

        $validInstalledMods = [];

        foreach ($metadata['installed_mods'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!empty(array_diff_key($requiredKeysFlipped, $entry))) {
                continue;
            }

            if (isset($validInstalledMods[$entry['project_id']])) {
                continue;
            }

            $validInstalledMods[$entry['project_id']] = $entry;
        }

        return array_values($validInstalledMods);
    }

    public function saveModMetadata(
        Server $server,
        ModrinthProjectType $modrinthProjectType,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null,
        ?string $installedAsDependencyOf = null
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $modrinthProjectType, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author, $installedAsDependencyOf) {
                $fileRepository = app(DaemonFileRepository::class);

                $installedMods = $this->getInstalledModsMetadata($server, $modrinthProjectType);

                $existingIndex = null;
                foreach ($installedMods as $index => $mod) {
                    if ($mod['project_id'] === $projectId) {
                        $existingIndex = $index;

                        break;
                    }
                }

                $modEntry = [
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => $existingIndex !== null
                        ? $installedMods[$existingIndex]['installed_at']
                        : now()->toIso8601String(),
                ];

                if ($existingIndex !== null) {
                    $modEntry['updated_at'] = now()->toIso8601String();
                }

                if ($author !== null) {
                    $modEntry['author'] = $author;
                }

                if ($installedAsDependencyOf !== null) {
                    $modEntry['installed_as_dependency_of'] = $installedAsDependencyOf;
                }

                if ($existingIndex !== null) {
                    // Keep keys this method doesn't write (`installed_as_dependency_of`, or ones another
                    // tool added) instead of dropping them on every update.
                    $modEntry += $installedMods[$existingIndex];

                    // Replace in place. Removing and re-appending pushed the entry to the end of the
                    // list, which moved the row to the bottom of the installed tab on every update.
                    $installedMods[$existingIndex] = $modEntry;
                } else {
                    $installedMods[] = $modEntry;
                }

                $metadataPath = $this->getMetadataFilePath($modrinthProjectType);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode(['installed_mods' => array_values($installedMods)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $modrinthProjectType, $projectId) {
                $fileRepository = app(DaemonFileRepository::class);

                $installedMods = collect($this->getInstalledModsMetadata($server, $modrinthProjectType))
                    ->filter(fn ($mod) => $mod['project_id'] !== $projectId)
                    ->values()
                    ->toArray();

                $metadataPath = $this->getMetadataFilePath($modrinthProjectType);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode(['installed_mods' => $installedMods], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * "Scan folder": finds jars in the project folder that the metadata file doesn't list,
     * looks them up on Modrinth by SHA-1 in one `POST /version_files` request, and records the
     * matches like an install would, plus `imported: true`. Jars without a match are remembered
     * as "Not on Modrinth" (see UnmatchedJars). Only the metadata file is ever written: no jar is
     * deleted, renamed, moved or rewritten.
     *
     * Hashing ruling: Wings has no checksum endpoint (its file API lists, reads, writes, copies,
     * renames, deletes, compresses and pulls; the panel's DaemonFileRepository has no hash call
     * either), and the listing carries only name/size/mode/mime/dates. So each untracked jar is
     * read through `getContent()` and hashed on the panel. Jars over SCAN_MAX_FILE_BYTES (also
     * enforced via `getContent()`'s size check), unreadable ones and names the page could not
     * handle safely later are skipped and reported instead.
     *
     * `duplicates` are Modrinth jars of a project the metadata already tracks under another file;
     * they are left alone. `error` is set when Modrinth or the metadata write failed: nothing is
     * imported then and the previous "Not on Modrinth" result is kept when the lookup failed.
     *
     * @return array{imported: list<string>, not_on_modrinth: list<string>, skipped: list<string>, duplicates: list<string>, error: bool}
     *
     * @throws Exception when the folder can't be listed
     */
    public function scanAndImport(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $result = ['imported' => [], 'not_on_modrinth' => [], 'skipped' => [], 'duplicates' => [], 'error' => false];

        $folder = $modrinthProjectType->getFolder();

        $untracked = UnmatchedJars::untracked(
            $this->listFolder($server, $folder),
            $this->getInstalledModsMetadata($server, $modrinthProjectType),
        );

        $fileRepository = app(DaemonFileRepository::class)->setServer($server);

        /** @var array<string, string> $hashes filename => sha1 */
        $hashes = [];
        /** @var array<string, int> $sizes */
        $sizes = [];

        foreach ($untracked as $file) {
            $name = $file['name'];

            if (!UnmatchedJars::isSafeName($name) || $file['size'] > self::SCAN_MAX_FILE_BYTES) {
                $result['skipped'][] = $name;

                continue;
            }

            try {
                $hashes[$name] = sha1($fileRepository->getContent(join_paths($folder, $name), self::SCAN_MAX_FILE_BYTES));
                $sizes[$name] = $file['size'];
            } catch (Exception $exception) {
                report($exception);

                $result['skipped'][] = $name;
            }
        }

        if ($hashes === []) {
            UnmatchedJars::remember($server->id, $modrinthProjectType, []);

            return $result;
        }

        $versionsByHash = $this->getVersionsByHashes(array_values(array_unique($hashes)));

        if ($versionsByHash === null) {
            $result['error'] = true;

            return $result;
        }

        /** @var array<string, array{id: string, project_id: string, version_number: string}> $matches */
        $matches = [];
        /** @var array<string, int> $notOnModrinth */
        $notOnModrinth = [];

        foreach ($hashes as $name => $hash) {
            $version = $versionsByHash[$hash] ?? null;

            if (is_array($version) && is_string($version['id'] ?? null) && is_string($version['project_id'] ?? null) && is_string($version['version_number'] ?? null)) {
                $matches[$name] = $version;
            } else {
                $notOnModrinth[$name] = $sizes[$name];
            }
        }

        $result['not_on_modrinth'] = array_map('strval', array_keys($notOnModrinth));

        if ($matches !== []) {
            $written = $this->importMatches($server, $modrinthProjectType, $matches);

            if ($written === null) {
                $result['error'] = true;
            } else {
                $result['imported'] = $written['imported'];
                $result['duplicates'] = $written['duplicates'];
            }
        }

        UnmatchedJars::remember($server->id, $modrinthProjectType, $notOnModrinth);

        return $result;
    }

    /**
     * Modrinth versions by SHA-1 (`POST /version_files`), keyed by hash; hashes Modrinth doesn't
     * know are missing. Null when the request failed, so an outage never reads as "not on Modrinth".
     *
     * @param  list<string>  $hashes
     * @return array<string, mixed>|null
     */
    protected function getVersionsByHashes(array $hashes): ?array
    {
        try {
            $versions = $this->modrinth(15)
                ->throw()
                ->post('version_files', [
                    'hashes' => $hashes,
                    'algorithm' => 'sha1',
                ])
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        return is_array($versions) ? $versions : null;
    }

    /**
     * Appends one metadata entry per matched jar in a single write, under the metadata lock. The
     * metadata is read again inside the lock, so a jar or project recorded meanwhile (an install,
     * another scan) is not added twice. Null when the write failed.
     *
     * @param  array<string, array{id: string, project_id: string, version_number: string}>  $matches  filename => version
     * @return array{imported: list<string>, duplicates: list<string>}|null
     */
    protected function importMatches(Server $server, ModrinthProjectType $modrinthProjectType, array $matches): ?array
    {
        $projects = $this->getProjectsByIds(array_values(array_unique(array_column($matches, 'project_id'))));

        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $modrinthProjectType, $matches, $projects) {
                $installedMods = $this->getInstalledModsMetadata($server, $modrinthProjectType);

                $trackedIds = array_fill_keys(array_column($installedMods, 'project_id'), true);
                $trackedFiles = array_fill_keys(array_map('strtolower', array_column($installedMods, 'filename')), true);

                $imported = [];
                $duplicates = [];

                foreach ($matches as $filename => $version) {
                    $filename = (string) $filename;

                    if (isset($trackedFiles[strtolower($filename)])) {
                        continue;
                    }

                    $projectId = $version['project_id'];

                    if (isset($trackedIds[$projectId])) {
                        $duplicates[] = $filename;

                        continue;
                    }

                    $project = $projects[$projectId] ?? [];
                    $title = is_string($project['title'] ?? null) ? $project['title'] : (string) preg_replace('/\.jar$/i', '', $filename);

                    $installedMods[] = [
                        'project_id' => $projectId,
                        'project_slug' => is_string($project['slug'] ?? null) ? $project['slug'] : $projectId,
                        'project_title' => $title,
                        'version_id' => $version['id'],
                        'version_number' => $version['version_number'],
                        'filename' => $filename,
                        'installed_at' => now()->toIso8601String(),
                        'imported' => true,
                    ];

                    $trackedIds[$projectId] = true;
                    $trackedFiles[strtolower($filename)] = true;
                    $imported[] = $title;
                }

                if ($imported !== []) {
                    $response = app(DaemonFileRepository::class)->setServer($server)->putContent(
                        $this->getMetadataFilePath($modrinthProjectType),
                        json_encode(['installed_mods' => array_values($installedMods)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );

                    if ($response->failed()) {
                        return null;
                    }
                }

                return ['imported' => $imported, 'duplicates' => $duplicates];
            });
        } catch (Exception $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Ask the daemon to download a file and wait until it is actually on disk.
     *
     * Without `foreground` the daemon downloads in the background, so the panel gets a success
     * response before the file exists and never hears about a failed download. The explicit file
     * name matters too: otherwise the daemon derives it from the URL, which can differ from the
     * name Modrinth reports and would leave the metadata pointing at a file that isn't there.
     *
     * @throws Exception
     */
    public function downloadFile(Server $server, string $url, string $folder, string $filename): void
    {
        // An update often reuses the name of the file it replaces. If the name is already taken,
        // finding it there afterwards says nothing about whether our download replaced it.
        $nameWasTaken = $this->fileExists($server, $folder, $filename);

        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->getHttpClient()
                // The daemon holds the request open for the whole download, which easily outlives
                // the timeout used for the small file operations everything else here does.
                ->timeout(max((int) config('panel.guzzle.timeout'), self::DOWNLOAD_TIMEOUT))
                ->post("/api/servers/{$server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => $folder,
                    'file_name' => $filename,
                    'foreground' => true,
                ]);
        } catch (Exception $exception) {
            // A slow download can outlive our request while still finishing on the node, but a
            // file appearing under a name that was free before is the only proof of that we get.
            if ($nameWasTaken) {
                throw $exception;
            }

            try {
                $landed = $this->fileExists($server, $folder, $filename);
            } catch (Exception) {
                throw $exception;
            }

            if (!$landed) {
                throw $exception;
            }

            return;
        }

        if (!$this->fileExists($server, $folder, $filename)) {
            throw new Exception("Daemon reported success but $folder/$filename is missing after downloading $url");
        }
    }

    /**
     * @throws Exception
     */
    public function deleteFile(Server $server, string $folder, string $filename): void
    {
        app(DaemonFileRepository::class)
            ->setServer($server)
            ->deleteFiles('/', [join_paths($folder, $filename)])
            ->throw();
    }

    public function fileExists(Server $server, string $folder, string $filename): bool
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist yet.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param  array{version_id: string, version_number: string}  $installedMod
     * @param  array<int, array{id: string, version_number: string}>  $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $metadata = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
