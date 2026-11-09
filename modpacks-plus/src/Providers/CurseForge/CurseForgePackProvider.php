<?php

namespace DrMxrcy\ModpacksPlus\Providers\CurseForge;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;
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

    private const CACHE_MINUTES = 10;

    private const MAX_BODY_CHARS = 20000;

    public function __construct(
        private readonly CurseForgeClient $client,
        private readonly ModrinthPackProvider $modrinth,
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

    public function caps(): Caps
    {
        return $this->caps;
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
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $mod) {
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
        $key = 'modpacks-plus:curseforge:categories';
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
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $category) {
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

        return Cache::remember("modpacks-plus:curseforge:project:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $mod = $this->mod($packId);

            try {
                $description = $this->client->get('mods/'.$packId.'/description');
                $body = is_string($description['data'] ?? null) ? self::htmlToText($description['data']) : '';
            } catch (PackUnavailableException) {
                $body = '';
            }

            $gallery = [];
            foreach (is_array($mod['screenshots'] ?? null) ? $mod['screenshots'] : [] as $image) {
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

        return Cache::remember("modpacks-plus:curseforge:versions:$packId", now()->addMinutes(self::CACHE_MINUTES), function () use ($packId) {
            $response = $this->client->get('mods/'.$packId.'/files', ['index' => 0, 'pageSize' => self::MAX_PAGE_SIZE]);

            $versions = [];
            foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $file) {
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

    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan
    {
        throw new PackUnavailableException('curseforge_unavailable', 'not supported yet');
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
        if ($fileId <= 0 || !self::isSafeFileName($fileName)) {
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
        foreach (is_array($mod['latestFilesIndexes'] ?? null) ? $mod['latestFilesIndexes'] : [] as $index) {
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
        foreach (is_array($mod['categories'] ?? null) ? $mod['categories'] : [] as $category) {
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
     */
    protected function archiveOf(array $file): ?PackArchive
    {
        $url = self::downloadUrl($file['downloadUrl'] ?? null);
        $name = is_string($file['fileName'] ?? null) ? $file['fileName'] : '';
        if ($url === null || !self::isSafeFileName($name)) {
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
        foreach (is_array($file['hashes'] ?? null) ? $file['hashes'] : [] as $hash) {
            if (is_array($hash) && ($hash['algo'] ?? null) === self::HASH_SHA1 && is_string($hash['value'] ?? null)) {
                return ['sha1' => strtolower($hash['value'])];
            }
        }

        return [];
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
