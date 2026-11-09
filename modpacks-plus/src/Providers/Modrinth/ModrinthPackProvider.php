<?php

namespace DrMxrcy\ModpacksPlus\Providers\Modrinth;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Modrinth modpacks (.mrpack, format version 1).
 *
 * Hash verification ruling for node (Wings) pulls: Wings has no checksum option and
 * the panel no hash endpoint, so for every file whose chosen URL is on
 * cdn.modrinth.com the declared sha512 is looked up through Modrinth's
 * `POST /version_files` before the pull, and the file is only marked
 * VERIFY_MODRINTH when Modrinth knows that exact hash at that exact URL (the CDN
 * then serves the bytes Modrinth hashed, over TLS); the size is checked after the
 * pull. Those are the only files the node ever pulls (Wings follows redirects on its
 * own, so it is never handed a URL whose redirects the panel can't vouch for). Every
 * other file (other hosts, or a lookup miss) is VERIFY_PANEL: the panel fetches it with
 * every redirect hop allowlisted and a byte cap (`max_panel_fetch_bytes`), hashes it,
 * and writes it through the daemon.
 *
 * @phpstan-import-type PackHit from PackProvider
 * @phpstan-import-type PackVersion from PackProvider
 */
class ModrinthPackProvider implements PackProvider
{
    public const ID = 'modrinth';

    /** mrpack dependency keys => loader names used by egg tags. */
    public const INDEX_LOADERS = [
        'fabric-loader' => 'fabric',
        'quilt-loader' => 'quilt',
        'forge' => 'forge',
        'neoforge' => 'neoforge',
    ];

    public const SORTS = ['relevance', 'downloads', 'follows', 'newest', 'updated'];

    private const INDEX_FILE = 'modrinth.index.json';

    private const MAX_INDEX_BYTES = 32 * 1024 * 1024;

    private const CACHE_MINUTES = 10;

    private const LOOKUP_CHUNK = 500;

    public function __construct(private readonly ModrinthClient $client, private readonly Caps $caps) {}

    public function id(): string
    {
        return self::ID;
    }

    /** Modrinth ids and slugs only; anything else never reaches a request path. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-.]{1,64}$/', $id) && !in_array($id, ['.', '..'], true);
    }

    public function sorts(): array
    {
        return self::SORTS;
    }

    public function packVersion(string $packId, string $versionId): array
    {
        $version = $this->version($versionId);
        if ($version['project_id'] !== $packId) {
            throw new PackUnavailableException('version_mismatch', $versionId);
        }

        return $version;
    }

    public function search(?string $query, ?string $minecraftVersion, ?string $loader, array $categories = [], ?string $sort = null, int $page = 1, int $perPage = 20): array
    {
        $facets = [['project_type:modpack']];
        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $facets[] = ['versions:'.$minecraftVersion];
        }
        if ($loader !== null && $loader !== '') {
            $facets[] = ['categories:'.$loader];
        }
        foreach ($categories as $category) {
            $facets[] = ['categories:'.$category];
        }

        $perPage = max(1, min(100, $perPage));

        $response = $this->get('search', array_filter([
            'query' => $query !== null && trim($query) !== '' ? trim($query) : null,
            'facets' => json_encode($facets),
            'index' => in_array($sort, self::SORTS, true) ? $sort : 'relevance',
            'limit' => $perPage,
            'offset' => (max(1, $page) - 1) * $perPage,
        ], fn ($value) => $value !== null));

        $hits = [];
        foreach (is_array($response['hits'] ?? null) ? $response['hits'] : [] as $hit) {
            if (is_array($hit) && is_string($hit['project_id'] ?? null) && self::isValidId($hit['project_id'])) {
                $hits[] = $this->normaliseHit($hit);
            }
        }

        return ['hits' => $hits, 'total' => is_int($response['total_hits'] ?? null) ? $response['total_hits'] : count($hits)];
    }

    public function categories(): array
    {
        return cache()->remember('modpacks-plus:modrinth:categories', now()->addHour(), function () {
            try {
                $tags = $this->get('tag/category');
            } catch (PackUnavailableException $exception) {
                report($exception);

                return [];
            }

            $names = [];
            foreach ($tags as $tag) {
                if (is_array($tag) && ($tag['project_type'] ?? null) === 'modpack' && is_string($tag['name'] ?? null) && ($tag['header'] ?? null) !== 'resolutions') {
                    $names[] = $tag['name'];
                }
            }
            $names = array_values(array_unique($names));
            sort($names);

            return $names;
        });
    }

    public function project(string $packId): array
    {
        $this->assertId($packId);

        return cache()->remember("modpacks-plus:modrinth:project:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $project = $this->get('project/'.rawurlencode($packId));

            if (($project['project_type'] ?? null) !== 'modpack' || !is_string($project['id'] ?? null)) {
                throw new PackUnavailableException('not_a_modpack', $packId);
            }

            $gallery = [];
            foreach (is_array($project['gallery'] ?? null) ? $project['gallery'] : [] as $image) {
                if (is_array($image) && is_string($image['url'] ?? null) && str_starts_with($image['url'], 'https://')) {
                    $gallery[] = [
                        'url' => $image['url'],
                        'title' => is_string($image['title'] ?? null) ? $image['title'] : null,
                    ];
                }
            }

            return [
                'id' => $project['id'],
                'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : $project['id'],
                'title' => is_string($project['title'] ?? null) ? $project['title'] : $project['id'],
                'description' => is_string($project['description'] ?? null) ? $project['description'] : '',
                'body' => is_string($project['body'] ?? null) ? $project['body'] : '',
                'icon_url' => self::httpsOrNull($project['icon_url'] ?? null),
                'gallery' => $gallery,
                'game_versions' => self::strings($project['game_versions'] ?? []),
                'loaders' => self::strings($project['loaders'] ?? []),
                'downloads' => is_int($project['downloads'] ?? null) ? $project['downloads'] : 0,
            ];
        });
    }

    public function versions(string $packId): array
    {
        $this->assertId($packId);

        return cache()->remember("modpacks-plus:modrinth:versions:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $versions = [];
            foreach ($this->get('project/'.rawurlencode($packId).'/version') as $version) {
                if (is_array($version) && is_string($version['id'] ?? null) && self::isValidId($version['id'])) {
                    $versions[] = $this->normaliseVersion($version);
                }
            }

            usort($versions, fn (array $a, array $b) => strcmp((string) $b['date_published'], (string) $a['date_published']));

            return $versions;
        });
    }

    /** @return PackVersion */
    public function version(string $versionId): array
    {
        $this->assertId($versionId);

        return $this->normaliseVersion($this->get('version/'.rawurlencode($versionId)));
    }

    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan
    {
        $version = $this->version($versionId);

        if ($version['project_id'] !== $packId) {
            throw new PackUnavailableException('version_mismatch', $versionId);
        }

        if ($version['file'] === null) {
            throw new PackUnavailableException('no_pack_file', $versionId);
        }

        $archive = PackArchive::fromArray($version['file']);

        // URL, declared size and hash format are checked before anything is downloaded.
        $validator->validate($this->emptyPlan($packId, $version, $archive));

        $path = rtrim($workDir, '/').'/'.PackArchive::WORK_FILE;
        $this->fetchArchive($archive, $path, $validator);

        $reader = PackArchiveReader::open($path, $validator, $this->caps);
        try {
            if (!$reader->has(self::INDEX_FILE)) {
                throw new PackUnavailableException('bad_index', 'missing '.self::INDEX_FILE);
            }

            $index = json_decode($reader->read(self::INDEX_FILE, self::MAX_INDEX_BYTES), true);
            $overrides = $reader->overrides($validator);
        } finally {
            $reader->close();
        }

        if (!is_array($index) || ($index['formatVersion'] ?? null) !== 1 || ($index['game'] ?? null) !== 'minecraft') {
            throw new PackUnavailableException('bad_index', self::INDEX_FILE);
        }

        [$minecraft, $loader, $loaderVersion] = self::target($index);

        $files = [];
        foreach (is_array($index['files'] ?? null) ? $index['files'] : [] as $file) {
            if (!is_array($file)) {
                throw new PackUnavailableException('bad_index', 'files');
            }

            $env = is_array($file['env'] ?? null) ? $file['env'] : [];
            $files[] = PlanFile::fromArray([
                'path' => $file['path'] ?? '',
                'urls' => $file['downloads'] ?? [],
                'size' => $file['fileSize'] ?? -1,
                'hashes' => $file['hashes'] ?? [],
                'side' => is_string($env['server'] ?? null) ? $env['server'] : 'required',
            ]);
        }

        $plan = $validator->validate($this->emptyPlan($packId, $version, $archive)->with([
            'minecraftVersion' => $minecraft,
            'loader' => $loader,
            'loaderVersion' => $loaderVersion,
            'packName' => is_string($index['name'] ?? null) && $index['name'] !== '' ? $index['name'] : $version['name'],
            'files' => $files,
            'overrides' => $overrides,
        ]));

        // Second pass: now that each file knows how it will be verified, the read-back cap applies.
        return $validator->validate($this->assignVerification($plan));
    }

    /**
     * Downloads the archive to $path on the panel and checks its size and sha512.
     * Reuses an existing file at $path when it already matches (a retried prepare).
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function fetchArchive(PackArchive $archive, string $path, PlanValidator $validator): void
    {
        if (is_file($path) && $this->archiveMatches($archive, $path)) {
            return;
        }

        // One archive download per job (prepare / overrides), so it may use most of the ~60 s.
        $this->client->download($archive->url, $path, min($this->caps->maxArchiveBytes, max(0, $archive->size)), $validator, 45);

        if (!$this->archiveMatches($archive, $path)) {
            @unlink($path);

            throw new UnsafePlanException('hash_mismatch', $archive->filename);
        }
    }

    private function archiveMatches(PackArchive $archive, string $path): bool
    {
        clearstatcache(true, $path);
        if (filesize($path) !== $archive->size) {
            return false;
        }

        [$algorithm, $expected] = PlanValidator::preferredHash($archive->hashes, $archive->filename);
        $actual = hash_file($algorithm, $path);

        return is_string($actual) && hash_equals($expected, $actual);
    }

    /**
     * Marks each file VERIFY_MODRINTH when Modrinth's version_files lookup knows its sha512
     * at exactly its chosen cdn.modrinth.com URL, else VERIFY_PANEL.
     *
     * @throws PackUnavailableException the lookup failed (the install is not guessed through)
     */
    public function assignVerification(InstallPlan $plan): InstallPlan
    {
        $wanted = [];
        foreach ($plan->files as $file) {
            if (isset($file->hashes['sha512']) && $file->url !== null && parse_url($file->url, PHP_URL_HOST) === 'cdn.modrinth.com') {
                $wanted[] = $file->hashes['sha512'];
            }
        }

        $known = [];
        foreach (array_chunk(array_values(array_unique($wanted)), self::LOOKUP_CHUNK) as $chunk) {
            $versions = $this->post('version_files', ['hashes' => $chunk, 'algorithm' => 'sha512']);

            foreach ($versions as $hash => $version) {
                if (!is_string($hash) || !is_array($version) || !is_array($version['files'] ?? null)) {
                    continue;
                }
                foreach ($version['files'] as $file) {
                    if (is_array($file) && is_string($file['url'] ?? null) && is_array($file['hashes'] ?? null)
                        && is_string($file['hashes']['sha512'] ?? null) && hash_equals(strtolower($hash), strtolower($file['hashes']['sha512']))) {
                        $known[strtolower($hash)][] = $file['url'];
                    }
                }
            }
        }

        return $plan->with(['files' => array_map(function (PlanFile $file) use ($known) {
            $sha512 = $file->hashes['sha512'] ?? null;
            $verified = $sha512 !== null && in_array($file->url, $known[$sha512] ?? [], true);

            return $file->with(['verify' => $verified ? PlanFile::VERIFY_MODRINTH : PlanFile::VERIFY_PANEL]);
        }, $plan->files)]);
    }

    /**
     * @param  array<mixed>  $index
     * @return array{string, string, ?string} [minecraft version, loader, loader version]
     *
     * @throws PackUnavailableException
     */
    public static function target(array $index): array
    {
        $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];

        $minecraft = $dependencies['minecraft'] ?? null;
        if (!is_string($minecraft) || !preg_match('/^[A-Za-z0-9.\-_+ ]{1,64}$/', $minecraft)) {
            throw new PackUnavailableException('bad_index', 'dependencies.minecraft');
        }

        $loaders = [];
        foreach (self::INDEX_LOADERS as $key => $loader) {
            if (array_key_exists($key, $dependencies)) {
                $loaders[$loader] = is_string($dependencies[$key]) ? $dependencies[$key] : null;
            }
        }

        if (count($loaders) !== 1) {
            throw new PackUnavailableException('bad_index', 'dependencies (loader)');
        }

        return [$minecraft, (string) array_key_first($loaders), reset($loaders)];
    }

    /** @param  PackVersion  $version */
    private function emptyPlan(string $packId, array $version, PackArchive $archive): InstallPlan
    {
        return new InstallPlan(
            provider: self::ID,
            packId: $packId,
            versionId: $version['id'],
            packName: $version['name'],
            versionNumber: $version['version_number'],
            minecraftVersion: $version['game_versions'][0] ?? '',
            loader: $version['loaders'][0] ?? '',
            loaderVersion: null,
            archive: $archive,
        );
    }

    /**
     * @param  array<mixed>  $hit
     * @return PackHit
     */
    private function normaliseHit(array $hit): array
    {
        return [
            'id' => (string) $hit['project_id'],
            'slug' => is_string($hit['slug'] ?? null) ? $hit['slug'] : (string) $hit['project_id'],
            'title' => is_string($hit['title'] ?? null) ? $hit['title'] : (string) $hit['project_id'],
            'description' => is_string($hit['description'] ?? null) ? $hit['description'] : '',
            'author' => is_string($hit['author'] ?? null) ? $hit['author'] : '',
            'icon_url' => self::httpsOrNull($hit['icon_url'] ?? null),
            'downloads' => is_int($hit['downloads'] ?? null) ? $hit['downloads'] : 0,
            'follows' => is_int($hit['follows'] ?? null) ? $hit['follows'] : 0,
            'date_modified' => is_string($hit['date_modified'] ?? null) ? $hit['date_modified'] : null,
            'categories' => self::strings($hit['display_categories'] ?? $hit['categories'] ?? []),
            'game_versions' => self::strings($hit['versions'] ?? []),
        ];
    }

    /**
     * @param  array<mixed>  $version
     * @return PackVersion
     */
    private function normaliseVersion(array $version): array
    {
        $file = null;
        $files = is_array($version['files'] ?? null) ? $version['files'] : [];
        // The primary .mrpack, else the first .mrpack.
        usort($files, fn ($a, $b) => (int) (is_array($b) && ($b['primary'] ?? false)) <=> (int) (is_array($a) && ($a['primary'] ?? false)));
        foreach ($files as $candidate) {
            if (is_array($candidate) && is_string($candidate['url'] ?? null) && is_string($candidate['filename'] ?? null)
                && str_ends_with(strtolower($candidate['filename']), '.mrpack')) {
                $file = [
                    'url' => $candidate['url'],
                    'filename' => $candidate['filename'],
                    'size' => is_int($candidate['size'] ?? null) ? $candidate['size'] : -1,
                    'hashes' => array_filter(is_array($candidate['hashes'] ?? null) ? $candidate['hashes'] : [], 'is_string'),
                ];
                break;
            }
        }

        return [
            'id' => is_string($version['id'] ?? null) ? $version['id'] : '',
            'project_id' => is_string($version['project_id'] ?? null) ? $version['project_id'] : '',
            'name' => is_string($version['name'] ?? null) ? $version['name'] : '',
            'version_number' => is_string($version['version_number'] ?? null) ? $version['version_number'] : '',
            'version_type' => is_string($version['version_type'] ?? null) ? $version['version_type'] : 'release',
            'game_versions' => self::strings($version['game_versions'] ?? []),
            'loaders' => self::strings($version['loaders'] ?? []),
            'date_published' => is_string($version['date_published'] ?? null) ? $version['date_published'] : null,
            'downloads' => is_int($version['downloads'] ?? null) ? $version['downloads'] : 0,
            'changelog' => is_string($version['changelog'] ?? null) ? $version['changelog'] : null,
            'file' => $file,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client->api()->get($path, $query);
        } catch (Throwable $exception) {
            throw new PackUnavailableException('api_unavailable', $path, $exception);
        }

        return $this->json($response, $path);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->client->api(20)->post($path, $body);
        } catch (Throwable $exception) {
            throw new PackUnavailableException('api_unavailable', $path, $exception);
        }

        return $this->json($response, $path);
    }

    /**
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function json(Response $response, string $path): array
    {
        if ($response->status() === 404) {
            throw new PackUnavailableException('not_found', $path);
        }

        $data = $response->successful() ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException('api_unavailable', $path.' (HTTP '.$response->status().')');
        }

        return $data;
    }

    /** @throws PackUnavailableException */
    private function assertId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new PackUnavailableException('not_found', $id);
        }
    }

    /** @return array<int, string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private static function httpsOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && !preg_match('/[\s"\'<>]/', $url) ? $url : null;
    }
}
