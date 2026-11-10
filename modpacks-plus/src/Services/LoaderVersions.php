<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Plan 40: the Minecraft and loader versions the "Minecraft version" picker offers, read from
 * the loaders' own metadata. Only the fixed URLs below are ever requested (no redirects, one
 * hop, HTTPS, the plugin's User-Agent, never decoded, MAX_BYTES at most, TIMEOUT seconds);
 * nothing from a user or an answer chooses a host or a path.
 *
 * - Minecraft releases: Mojang's version manifest (`type: release`); a loader's list is
 *   intersected with it, so only releases are offered (snapshot ids are not versions the
 *   loader eggs take, EggVariableMap).
 * - Fabric / Quilt: their meta's `stable` game versions; Fabric's stable loader builds
 *   (Quilt's egg always installs the latest loader, so it has none).
 * - NeoForge: its maven metadata, release builds only (no `-beta`): `21.1.77` is Minecraft
 *   1.21.1, `21.0.x` 1.21, `26.1.2.126` 26.1.2, `26.3.0.102` 26.3.
 * - Forge: its maven metadata, `<minecraft>-<forge>` entries only.
 *
 * Parsed lists are cached for CACHE_SECONDS per URL; a failure for FAILURE_SECONDS, so an
 * outage is not asked again on every keystroke. Any failure is `versions_unavailable` with
 * the host, never a partial list.
 */
class LoaderVersions
{
    public const MOJANG_URL = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';

    public const FABRIC_GAME_URL = 'https://meta.fabricmc.net/v2/versions/game';

    public const FABRIC_LOADER_URL = 'https://meta.fabricmc.net/v2/versions/loader';

    public const QUILT_GAME_URL = 'https://meta.quiltmc.org/v3/versions/game';

    public const NEOFORGE_URL = 'https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml';

    public const FORGE_URL = 'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml';

    /** Every URL this class may request; anything else is refused before sending. */
    public const URLS = [self::MOJANG_URL, self::FABRIC_GAME_URL, self::FABRIC_LOADER_URL, self::QUILT_GAME_URL, self::NEOFORGE_URL, self::FORGE_URL];

    /** Largest answer read (Mojang's manifest is ~300 KB, Forge's metadata ~220 KB). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Most entries read from one answer. */
    public const MAX_ENTRIES = 10000;

    /** Loader versions offered per Minecraft version (newest first). */
    public const MAX_LOADER_VERSIONS = 50;

    public const TIMEOUT = 5;

    public const CACHE_SECONDS = 3600;

    public const FAILURE_SECONDS = 60;

    private const CACHE_PREFIX = 'modpacks-plus:loader-versions:';

    /** What the loader eggs take as a Minecraft version (EggVariableMap): releases only. */
    private const RELEASE_PATTERN = '/^\d{1,2}\.\d{1,2}(\.\d{1,2})?$/';

    /** @var array<string, array<int, mixed>> parsed answers, this request */
    private array $memo = [];

    /**
     * Minecraft version => the loader versions offered for it (newest first; [] for a loader
     * whose egg doesn't pin one), newest Minecraft version first.
     *
     * @return array<string, array<int, string>>
     *
     * @throws PackUnavailableException `versions_unavailable` (host) | `loader_unsupported`
     */
    public function versions(string $loader): array
    {
        if (!in_array($loader, EggVariableMap::loaders(), true)) {
            throw new PackUnavailableException('loader_unsupported', $loader);
        }

        $releases = array_flip($this->releases());

        $byMinecraft = match ($loader) {
            'fabric' => $this->withLoaders($this->stableGames(self::FABRIC_GAME_URL), $this->fabricLoaders()),
            'quilt' => $this->withLoaders($this->stableGames(self::QUILT_GAME_URL), []),
            'neoforge' => $this->neoforge(),
            default => $this->forge(),
        };

        $offered = array_filter($byMinecraft, fn (string $minecraft) => isset($releases[$minecraft]), ARRAY_FILTER_USE_KEY);
        if (EggVariableMap::pinsLoaderVersion($loader)) {
            $offered = array_filter($offered, fn (array $versions) => $versions !== []);
        }
        uksort($offered, fn (string $a, string $b) => version_compare($b, $a));

        return array_map(fn (array $versions) => array_slice($versions, 0, self::MAX_LOADER_VERSIONS), $offered);
    }

    /**
     * Whether $minecraft (and $loaderVersion, for a loader whose egg pins one) is offered for
     * $loader right now: the server-side check of a submitted choice.
     *
     * @throws PackUnavailableException
     */
    public function offers(string $loader, string $minecraft, ?string $loaderVersion): bool
    {
        $versions = $this->versions($loader);
        if (!array_key_exists($minecraft, $versions)) {
            return false;
        }

        if (!EggVariableMap::pinsLoaderVersion($loader)) {
            return $loaderVersion === null || $loaderVersion === '';
        }

        return $loaderVersion !== null && in_array($loaderVersion, $versions[$minecraft], true);
    }

    /**
     * Mojang's release ids.
     *
     * @return array<int, string>
     *
     * @throws PackUnavailableException
     */
    public function releases(): array
    {
        return $this->cached(self::MOJANG_URL, function (string $body) {
            $data = json_decode($body, true);
            if (!is_array($data) || !is_array($data['versions'] ?? null)) {
                return null;
            }

            $releases = [];
            foreach (array_slice($data['versions'], 0, self::MAX_ENTRIES) as $version) {
                if (is_array($version) && ($version['type'] ?? null) === 'release' && is_string($version['id'] ?? null) && preg_match(self::RELEASE_PATTERN, $version['id'])) {
                    $releases[] = $version['id'];
                }
            }

            return $releases;
        });
    }

    /**
     * @return array<int, string>
     *
     * @throws PackUnavailableException
     */
    private function stableGames(string $url): array
    {
        return $this->cached($url, function (string $body) {
            $data = json_decode($body, true);
            if (!is_array($data) || !array_is_list($data)) {
                return null;
            }

            $games = [];
            foreach (array_slice($data, 0, self::MAX_ENTRIES) as $entry) {
                if (is_array($entry) && ($entry['stable'] ?? false) === true && is_string($entry['version'] ?? null) && preg_match(self::RELEASE_PATTERN, $entry['version'])) {
                    $games[] = $entry['version'];
                }
            }

            return $games;
        });
    }

    /**
     * Fabric's stable loader builds, newest first (as the meta lists them).
     *
     * @return array<int, string>
     *
     * @throws PackUnavailableException
     */
    private function fabricLoaders(): array
    {
        return $this->cached(self::FABRIC_LOADER_URL, function (string $body) {
            $data = json_decode($body, true);
            if (!is_array($data) || !array_is_list($data)) {
                return null;
            }

            $loaders = [];
            foreach (array_slice($data, 0, self::MAX_ENTRIES) as $entry) {
                // The Fabric egg takes 3 to 15 characters.
                if (is_array($entry) && ($entry['stable'] ?? false) === true && is_string($entry['version'] ?? null) && preg_match('/^\d{1,4}(\.\d{1,4}){1,3}$/', $entry['version'])) {
                    $loaders[] = $entry['version'];
                }
            }
            usort($loaders, fn (string $a, string $b) => version_compare($b, $a));

            return array_slice($loaders, 0, self::MAX_LOADER_VERSIONS);
        });
    }

    /**
     * @return array<string, array<int, string>>
     *
     * @throws PackUnavailableException
     */
    private function neoforge(): array
    {
        $versions = $this->cached(self::NEOFORGE_URL, fn (string $body) => self::mavenVersions($body));

        $byMinecraft = [];
        foreach ($versions as $version) {
            $minecraft = self::neoforgeMinecraft($version);
            if ($minecraft !== null) {
                $byMinecraft[$minecraft][] = $version;
            }
        }

        return array_map(fn (array $builds) => self::newestFirst($builds), $byMinecraft);
    }

    /** The Minecraft version a NeoForge release build is for, or null (betas, unknown shapes). */
    public static function neoforgeMinecraft(string $version): ?string
    {
        // Year-numbered: <year>.<drop>.<hotfix>.<build> (26.1.2.126 is 26.1.2, 26.3.0.102 is 26.3).
        if (preg_match('/^(\d{2})\.(\d{1,2})\.(\d{1,2})\.\d{1,5}$/', $version, $match) && (int) $match[1] >= 25) {
            return $match[1].'.'.$match[2].($match[3] !== '0' ? '.'.$match[3] : '');
        }

        // Before: <minor>.<patch>.<build> of 1.x (21.1.77 is 1.21.1, 21.0.167 is 1.21).
        if (preg_match('/^(\d{2})\.(\d{1,2})\.\d{1,5}$/', $version, $match) && (int) $match[1] >= 20 && (int) $match[1] < 25) {
            return '1.'.$match[1].($match[2] !== '0' ? '.'.$match[2] : '');
        }

        return null;
    }

    /**
     * Forge versions by Minecraft version: `<minecraft>-<forge>` entries only, the value as
     * the Forge egg's FORGE_VERSION takes it (with the Minecraft prefix).
     *
     * @return array<string, array<int, string>>
     *
     * @throws PackUnavailableException
     */
    private function forge(): array
    {
        $versions = $this->cached(self::FORGE_URL, fn (string $body) => self::mavenVersions($body));

        $byMinecraft = [];
        foreach ($versions as $version) {
            if (preg_match('/^(\d{1,2}\.\d{1,2}(?:\.\d{1,2})?)-(\d{1,4}(?:\.\d{1,5}){1,3})$/', $version, $match)) {
                $byMinecraft[$match[1]][] = $version;
            }
        }

        return array_map(fn (array $builds) => self::newestFirst($builds), $byMinecraft);
    }

    /**
     * @param  array<int, string>  $loaders
     * @param  array<int, string>  $games
     * @return array<string, array<int, string>>
     */
    private function withLoaders(array $games, array $loaders): array
    {
        return array_fill_keys(array_values(array_unique($games)), $loaders);
    }

    /**
     * The `<version>` entries of a maven-metadata.xml, read with a pattern (no XML parser, so
     * no entities or external references).
     *
     * @return array<int, string>|null
     */
    private static function mavenVersions(string $body): ?array
    {
        if (!str_contains($body, '<versioning>')) {
            return null;
        }

        preg_match_all('#<version>\s*([0-9A-Za-z.+\-]{1,64})\s*</version>#', $body, $matches);

        return array_slice($matches[1], 0, self::MAX_ENTRIES);
    }

    /**
     * @param  array<int, string>  $versions
     * @return array<int, string>
     */
    private static function newestFirst(array $versions): array
    {
        $versions = array_values(array_unique($versions));
        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        return $versions;
    }

    /**
     * The parsed answer of $url: from this request, the cache, or one bounded GET.
     *
     * @param  \Closure(string): (array<int|string, mixed>|null)  $parse  null when the answer is not what was expected
     * @return array<int|string, mixed>
     *
     * @throws PackUnavailableException
     */
    private function cached(string $url, \Closure $parse): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (isset($this->memo[$url])) {
            return $this->memo[$url];
        }

        $key = self::CACHE_PREFIX.sha1($url);
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('failed', $cached)) {
            throw new PackUnavailableException('versions_unavailable', $host);
        }
        if (is_array($cached) && is_array($cached['data'] ?? null)) {
            return $this->memo[$url] = $cached['data'];
        }

        $data = null;
        try {
            $body = $this->fetch($url);
            $data = $body !== null ? $parse($body) : null;
        } catch (Throwable $exception) {
            report($exception);
        }

        if (!is_array($data)) {
            Cache::put($key, ['failed' => true], self::FAILURE_SECONDS);

            throw new PackUnavailableException('versions_unavailable', $host);
        }

        Cache::put($key, ['data' => $data], self::CACHE_SECONDS);

        return $this->memo[$url] = $data;
    }

    /** One GET of an allowlisted URL; null for anything but a plain 200 within the caps. */
    private function fetch(string $url): ?string
    {
        if (!in_array($url, self::URLS, true)) {
            return null;
        }

        try {
            $response = Http::withUserAgent(app(ModrinthClient::class)->userAgent())
                ->timeout(self::TIMEOUT)
                ->connectTimeout(3)
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions(['allow_redirects' => false] + ModrinthClient::boundedOptions(self::MAX_BYTES))
                ->beforeSending(function (Request $request) {
                    if (!in_array($request->url(), self::URLS, true)) {
                        throw new \RuntimeException('blocked');
                    }
                })
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() !== 200 || !ModrinthClient::isIdentityEncoding($response->header('Content-Encoding'))) {
            return null;
        }

        $length = $response->header('Content-Length');
        $body = $response->body();
        if (($length !== '' && (int) $length > self::MAX_BYTES) || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return $body;
    }
}
