<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Cache;
use stdClass;
use Throwable;

/**
 * Records a pack's mods in `mods/.modrinth-metadata.json`, the file the official
 * minecraft-modrinth plugin and Modrinth Plus use to know what is installed, so those
 * mods show up there as installed and can be updated one by one (plan 33). The shared
 * file is the only link: nothing here needs Modrinth Plus installed.
 *
 * - Only `mods/<name>.jar` files of the plan that are on disk with the planned size, and
 *   that Modrinth knows by hash (`POST /version_files`, sha512, else sha1).
 * - Entries use Modrinth Plus's shape and key order, plus `installed_as_modpack`
 *   {provider, pack_id, version_id, pack_name, version_number}.
 * - The file is only appended to or merged into: unknown top-level and entry keys, entry
 *   order, entries this plugin did not write (pinned or not) and `{}` vs `[]` are kept; it
 *   is written pretty printed with unescaped slashes, like Modrinth Plus does.
 * - An entry for the same project is merged (version, file name, pack tag; `pinned`,
 *   `installed_at` and anything else kept) only when it came from a pack or names the same
 *   jar; a user's own entry for another jar is left alone ("kept"), and a jar name another
 *   project's entry already claims is skipped ("conflicts").
 * - Fail closed: a file that can't be read (daemon error, timeout, too large) or isn't a
 *   metadata document is never written. Missing or empty is an empty document.
 * - Read and write happen under Modrinth Plus's own lock, `modrinth_metadata:{server id}`
 *   on the panel's default cache store, so the two plugins never interleave writes.
 */
class ModrinthMetadataWriter
{
    public const PATH = 'mods/.modrinth-metadata.json';

    /** Shared with Modrinth Plus (MinecraftModrinthService): same name, same TTL. */
    public const LOCK_PREFIX = 'modrinth_metadata:';

    public const LOCK_SECONDS = 10;

    public const PACK_KEY = 'installed_as_modpack';

    /** A metadata file larger than this is treated as unreadable (never rewritten). */
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const LOOKUP_CHUNK = 500;

    private const PROJECTS_CHUNK = 100;

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /** @param  int  $lockWait  seconds to wait for the metadata lock */
    public function __construct(private readonly ModrinthClient $client, private readonly int $lockWait = 5) {}

    /**
     * What happened, for the install summary. `status` is one of: written, unchanged (every
     * match already recorded, kept or conflicting), nothing (no mod of the pack is on
     * Modrinth), lookup_failed, unreadable, invalid, busy, write_failed.
     *
     * @return array{status: string, added: int, updated: int, already: int, kept: list<string>, conflicts: list<string>}
     */
    public function record(Server $server, ServerFiles $files, PackInstall $install): array
    {
        $result = ['status' => 'nothing', 'added' => 0, 'updated' => 0, 'already' => 0, 'kept' => [], 'conflicts' => []];

        $plan = $install->installPlan();
        if ($plan === null) {
            return $result;
        }

        try {
            $candidates = $this->candidates($server, $files, $plan->files);
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unreadable'] + $result;
        }

        if ($candidates === []) {
            return $result;
        }

        $matches = $this->lookup($candidates);
        if ($matches === null) {
            return ['status' => 'lookup_failed'] + $result;
        }
        if ($matches === []) {
            return $result;
        }

        $projects = $this->projects(array_values(array_unique(array_column($matches, 'project_id'))));
        $tag = [
            'provider' => $install->provider,
            'pack_id' => $install->pack_id,
            'version_id' => $install->version_id,
            'pack_name' => $install->name,
            'version_number' => $install->version_number ?? $plan->versionNumber,
        ];

        try {
            return Cache::lock(self::LOCK_PREFIX.$server->id, self::LOCK_SECONDS)
                ->block($this->lockWait, fn () => $this->merge($server, $files, $matches, $projects, $tag, $result));
        } catch (LockTimeoutException) {
            return ['status' => 'busy'] + $result;
        }
    }

    /**
     * Plan files that are top-level jars in mods/, on disk with the planned size, keyed by
     * file name, with the hash to look up.
     *
     * @param  array<int, PlanFile>  $planFiles
     * @return array<string, array{algorithm: string, hash: string}>
     */
    private function candidates(Server $server, ServerFiles $files, array $planFiles): array
    {
        $wanted = [];
        foreach ($planFiles as $file) {
            if (!preg_match('#^mods/[^/]+\.jar$#i', $file->path)) {
                continue;
            }

            $hash = match (true) {
                (bool) preg_match('/^[0-9a-f]{128}$/', strtolower($file->hashes['sha512'] ?? '')) => ['algorithm' => 'sha512', 'hash' => strtolower($file->hashes['sha512'])],
                (bool) preg_match('/^[0-9a-f]{40}$/', strtolower($file->hashes['sha1'] ?? '')) => ['algorithm' => 'sha1', 'hash' => strtolower($file->hashes['sha1'])],
                default => null,
            };

            if ($hash !== null && !isset($wanted[$file->filename()])) {
                $wanted[$file->filename()] = ['size' => $file->size] + $hash;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $listing = $files->list($server, 'mods') ?? [];
        $candidates = [];
        foreach ($wanted as $name => $file) {
            $entry = $listing[$name] ?? null;
            if ($entry !== null && !$entry['directory'] && $entry['size'] === $file['size']) {
                $candidates[(string) $name] = ['algorithm' => $file['algorithm'], 'hash' => $file['hash']];
            }
        }

        return $candidates;
    }

    /**
     * Modrinth versions of the candidates by hash; null when a lookup failed (nothing is
     * guessed through an outage).
     *
     * @param  array<string, array{algorithm: string, hash: string}>  $candidates
     * @return array<string, array{project_id: string, version_id: string, version_number: string}>|null filename => version
     */
    private function lookup(array $candidates): ?array
    {
        $versions = [];
        foreach (['sha512', 'sha1'] as $algorithm) {
            $hashes = array_values(array_unique(array_column(array_filter($candidates, fn (array $file) => $file['algorithm'] === $algorithm), 'hash')));

            foreach (array_chunk($hashes, self::LOOKUP_CHUNK) as $chunk) {
                try {
                    $response = $this->client->api(20)->post('version_files', ['hashes' => $chunk, 'algorithm' => $algorithm]);
                } catch (Throwable $exception) {
                    report($exception);

                    return null;
                }

                $data = $response->successful() ? $response->json() : null;
                if (!is_array($data)) {
                    return null;
                }

                foreach ($data as $hash => $version) {
                    if (is_string($hash) && is_array($version)) {
                        $versions[$algorithm][strtolower($hash)] = $version;
                    }
                }
            }
        }

        $matches = [];
        foreach ($candidates as $name => $file) {
            $version = $versions[$file['algorithm']][$file['hash']] ?? null;
            if (!is_array($version) || !self::knowsHash($version, $file['algorithm'], $file['hash'])) {
                continue;
            }

            $projectId = $version['project_id'] ?? null;
            $versionId = $version['id'] ?? null;
            $number = $version['version_number'] ?? null;
            if (is_string($projectId) && ModrinthPackProvider::isValidId($projectId) && is_string($versionId) && ModrinthPackProvider::isValidId($versionId)
                && is_string($number) && $number !== '' && mb_strlen($number) <= 128) {
                $matches[$name] = ['project_id' => $projectId, 'version_id' => $versionId, 'version_number' => $number];
            }
        }

        return $matches;
    }

    /**
     * The answer is about this exact file: one of the version's files has the hash.
     *
     * @param  array<mixed>  $version
     */
    private static function knowsHash(array $version, string $algorithm, string $hash): bool
    {
        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $file) {
            $known = is_array($file) && is_array($file['hashes'] ?? null) ? ($file['hashes'][$algorithm] ?? null) : null;
            if (is_string($known) && hash_equals($hash, strtolower($known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slug and title per project id, best effort: on failure the entry falls back to the id
     * and the jar name, as Modrinth Plus's "Scan folder" does.
     *
     * @param  list<string>  $ids
     * @return array<string, array{slug: string, title: string}>
     */
    private function projects(array $ids): array
    {
        $projects = [];
        foreach (array_chunk($ids, self::PROJECTS_CHUNK) as $chunk) {
            try {
                $response = $this->client->api()->get('projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)]);
                $data = $response->successful() ? $response->json() : null;
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach (is_array($data) ? $data : [] as $project) {
                if (is_array($project) && is_string($project['id'] ?? null) && is_string($project['slug'] ?? null) && is_string($project['title'] ?? null)) {
                    $projects[$project['id']] = ['slug' => $project['slug'], 'title' => mb_strimwidth($project['title'], 0, 250, '…')];
                }
            }
        }

        return $projects;
    }

    /**
     * Reads, merges and writes the file; runs under the metadata lock.
     *
     * @param  array<string, array{project_id: string, version_id: string, version_number: string}>  $matches
     * @param  array<string, array{slug: string, title: string}>  $projects
     * @param  array<string, string>  $tag
     * @param  array{status: string, added: int, updated: int, already: int, kept: list<string>, conflicts: list<string>}  $result
     * @return array{status: string, added: int, updated: int, already: int, kept: list<string>, conflicts: list<string>}
     */
    private function merge(Server $server, ServerFiles $files, array $matches, array $projects, array $tag, array $result): array
    {
        try {
            $content = $files->read($server, self::PATH, self::MAX_BYTES);
        } catch (FileNotFoundException) {
            $content = '';
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unreadable'] + $result;
        }

        $document = self::parse($content);
        if ($document === null) {
            return ['status' => 'invalid'] + $result;
        }

        /** @var list<mixed> $mods */
        $mods = $document->installed_mods ?? [];
        $now = now()->toIso8601String();
        /** @var array<string, true> $handled projects already recorded from this pack */
        $handled = [];

        foreach ($matches as $filename => $version) {
            $filename = (string) $filename;

            // Two jars of one project in a pack: the entry can describe only one of them.
            if (isset($handled[$version['project_id']])) {
                $result['conflicts'][] = $filename;

                continue;
            }
            $handled[$version['project_id']] = true;
            $byProject = null;
            $claimedByOther = false;

            foreach ($mods as $index => $mod) {
                if (!$mod instanceof stdClass || !is_string($mod->project_id ?? null)) {
                    continue;
                }
                if ($mod->project_id === $version['project_id']) {
                    $byProject ??= $index;
                } elseif (is_string($mod->filename ?? null) && strcasecmp($mod->filename, $filename) === 0) {
                    $claimedByOther = true;
                }
            }

            if ($byProject !== null) {
                /** @var stdClass $entry */
                $entry = $mods[$byProject];
                $fromPack = isset($entry->{self::PACK_KEY});
                $sameJar = is_string($entry->filename ?? null) && strcasecmp($entry->filename, $filename) === 0;

                if (!$fromPack && !$sameJar) {
                    $result['kept'][] = $filename;

                    continue;
                }

                if (($entry->version_id ?? null) === $version['version_id'] && ($entry->filename ?? null) === $filename
                    && json_encode($entry->{self::PACK_KEY} ?? null) === json_encode((object) $tag)) {
                    $result['already']++;

                    continue;
                }

                $project = $projects[$version['project_id']] ?? null;
                $entry->project_slug ??= $project['slug'] ?? $version['project_id'];
                $entry->project_title ??= $project['title'] ?? self::titleFromJar($filename);
                $entry->version_id = $version['version_id'];
                $entry->version_number = $version['version_number'];
                $entry->filename = $filename;
                $entry->installed_at ??= $now;
                $entry->{self::PACK_KEY} = (object) $tag;
                $entry->updated_at = $now;
                $result['updated']++;

                continue;
            }

            if ($claimedByOther) {
                $result['conflicts'][] = $filename;

                continue;
            }

            $project = $projects[$version['project_id']] ?? null;
            $mods[] = (object) [
                'project_id' => $version['project_id'],
                'project_slug' => $project['slug'] ?? $version['project_id'],
                'project_title' => $project['title'] ?? self::titleFromJar($filename),
                'version_id' => $version['version_id'],
                'version_number' => $version['version_number'],
                'filename' => $filename,
                'installed_at' => $now,
                self::PACK_KEY => (object) $tag,
            ];
            $result['added']++;
        }

        if ($result['added'] === 0 && $result['updated'] === 0) {
            return ['status' => 'unchanged'] + $result;
        }

        $document->installed_mods = $mods;

        try {
            $files->write($server, self::PATH, json_encode($document, self::FLAGS));
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'write_failed'] + $result;
        }

        return ['status' => 'written'] + $result;
    }

    /**
     * The document as objects (so `{}` stays `{}`), or null when it is not a metadata
     * document: not JSON, not an object, or `installed_mods` not a list. Empty, `{}` and `[]`
     * are an empty document, as for Modrinth Plus.
     */
    private static function parse(string $content): ?stdClass
    {
        if (trim($content) === '') {
            return new stdClass();
        }

        try {
            $document = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if ($document === []) {
            return new stdClass();
        }

        if (!$document instanceof stdClass) {
            return null;
        }

        if (property_exists($document, 'installed_mods') && (!is_array($document->installed_mods) || !array_is_list($document->installed_mods))) {
            return null;
        }

        return $document;
    }

    private static function titleFromJar(string $filename): string
    {
        return (string) preg_replace('/\.jar$/i', '', $filename);
    }
}
