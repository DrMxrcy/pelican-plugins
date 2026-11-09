<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Finds CurseForge pack files on Modrinth by sha1 (plan 31, "Modrinth-first"): one batched
 * `POST /v2/version_files` (algorithm sha1) per 500 hashes, then the projects' `server_side`
 * through `GET /v2/projects` (batched). A match gives:
 * - the cdn.modrinth.com URL and Modrinth's sha512 of the same bytes, so the file can be
 *   downloaded from Modrinth instead (still only pulled by the node once the sha512 lookup
 *   of item 30 vouches for that exact URL; panel-fetched and sha512-checked otherwise);
 * - Modrinth's side data, so client-only mods are dropped (CurseForge has no reliable flag).
 *
 * Only answers about the exact hash count (a version file whose own sha1 is the asked one).
 * Results are cached per hash (hits 1 h, misses 10 min). A failed lookup throws: nothing is
 * guessed through an outage (a missed client-only mod could crash the server).
 */
class ModrinthMatcher
{
    private const CHUNK = 500;

    private const PROJECT_CHUNK = 100;

    private const SIDES = ['required', 'optional', 'unsupported', 'unknown'];

    private const CACHE_PREFIX = 'modpacks-plus:modrinth-sha1:';

    /** Below the 1000-file manifest cap, so a hostile manifest can't fill the cache with misses. */
    private const MAX_CACHED_MISSES = 100;

    public function __construct(private readonly ModrinthClient $client) {}

    /**
     * @param  array<int, string>  $sha1s
     * @return array<string, array{project_id: string, version_id: string, url: string, sha512: string, size: int, server_side: string, project_slug?: string}> by sha1
     *
     * @throws PackUnavailableException
     */
    public function match(array $sha1s): array
    {
        $wanted = [];
        foreach ($sha1s as $sha1) {
            $sha1 = strtolower($sha1);
            if (preg_match('/^[0-9a-f]{40}$/', $sha1)) {
                $wanted[$sha1] = true;
            }
        }

        $matches = [];
        $todo = [];
        foreach (array_keys($wanted) as $sha1) {
            $cached = Cache::get(self::CACHE_PREFIX.$sha1);
            if (is_array($cached)) {
                /** @var array{project_id: string, version_id: string, url: string, sha512: string, size: int, server_side: string, project_slug?: string} $cached */
                $matches[$sha1] = $cached;
            } elseif ($cached !== false) {
                $todo[] = (string) $sha1;
            }
        }

        $found = [];
        foreach (array_chunk($todo, self::CHUNK) as $chunk) {
            foreach ($this->post('version_files', ['hashes' => $chunk, 'algorithm' => 'sha1']) as $hash => $version) {
                $hash = strtolower((string) $hash);
                if (in_array($hash, $chunk, true) && is_array($version) && ($file = self::file($version, $hash)) !== null) {
                    $found[$hash] = $file;
                }
            }
        }

        $projects = $this->sides(array_values(array_unique(array_column($found, 'project_id'))));

        // Misses are only remembered for an ordinary pack's worth of hashes, so the cache can't be
        // filled with arbitrary keys.
        $cacheMisses = count($todo) <= self::MAX_CACHED_MISSES;
        foreach ($todo as $sha1) {
            if (isset($found[$sha1])) {
                $project = $projects[$found[$sha1]['project_id']] ?? null;
                $match = $found[$sha1] + [
                    'server_side' => $project['side'] ?? 'unknown',
                    'project_slug' => $project['slug'] ?? $found[$sha1]['project_id'],
                ];
                Cache::put(self::CACHE_PREFIX.$sha1, $match, now()->addHour());
                $matches[$sha1] = $match;
            } elseif ($cacheMisses) {
                Cache::put(self::CACHE_PREFIX.$sha1, false, now()->addMinutes(10));
            }
        }

        return $matches;
    }

    /**
     * The version's file with exactly this sha1, on cdn.modrinth.com with a sha512.
     *
     * @param  array<mixed>  $version
     * @return array{project_id: string, version_id: string, url: string, sha512: string, size: int}|null
     */
    private static function file(array $version, string $sha1): ?array
    {
        $projectId = $version['project_id'] ?? null;
        $versionId = $version['id'] ?? null;
        if (!is_string($projectId) || !ModrinthPackProvider::isValidId($projectId) || !is_string($versionId) || !ModrinthPackProvider::isValidId($versionId)) {
            return null;
        }

        $validator = new PlanValidator(new Caps(), ['world'], ['cdn.modrinth.com']);
        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $file) {
            $hashes = is_array($file) && is_array($file['hashes'] ?? null) ? $file['hashes'] : [];
            $fileSha1 = is_string($hashes['sha1'] ?? null) ? strtolower($hashes['sha1']) : '';
            $sha512 = is_string($hashes['sha512'] ?? null) ? strtolower($hashes['sha512']) : '';
            $url = is_array($file) && is_string($file['url'] ?? null) ? $file['url'] : '';

            if (hash_equals($sha1, $fileSha1) && preg_match('/^[0-9a-f]{128}$/', $sha512) && $validator->isAllowedUrl($url)
                && is_int($file['size'] ?? null) && $file['size'] >= 0) {
                return ['project_id' => $projectId, 'version_id' => $versionId, 'url' => $url, 'sha512' => $sha512, 'size' => $file['size']];
            }
        }

        return null;
    }

    /**
     * Each project's server_side and slug.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array{side: string, slug: string}>
     *
     * @throws PackUnavailableException
     */
    private function sides(array $projectIds): array
    {
        $sides = [];
        foreach (array_chunk($projectIds, self::PROJECT_CHUNK) as $chunk) {
            foreach (array_slice($this->get('projects', ['ids' => json_encode($chunk)]), 0, count($chunk)) as $project) {
                if (is_array($project) && is_string($project['id'] ?? null) && in_array($project['id'], $chunk, true)) {
                    $side = $project['server_side'] ?? null;
                    $slug = $project['slug'] ?? null;
                    $sides[$project['id']] = [
                        'side' => is_string($side) && in_array($side, self::SIDES, true) ? $side : 'unknown',
                        'slug' => is_string($slug) && ModrinthPackProvider::isValidId($slug) ? $slug : $project['id'],
                    ];
                }
            }
        }

        return $sides;
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

        $data = $response->successful() ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException('api_unavailable', $path.' (HTTP '.$response->status().')');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PackUnavailableException
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->client->api(20)->get($path, $query);
        } catch (Throwable $exception) {
            throw new PackUnavailableException('api_unavailable', $path, $exception);
        }

        $data = $response->successful() ? $response->json() : null;
        if (!is_array($data)) {
            throw new PackUnavailableException('api_unavailable', $path.' (HTTP '.$response->status().')');
        }

        return $data;
    }
}
