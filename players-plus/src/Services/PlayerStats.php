<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Support\DaemonFiles;
use DrMxrcy\PlayersPlus\Support\ServerProperties;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Vanilla statistics from `<level-name>/stats/<uuid>.json`, read through the daemon when a
 * profile is opened (never by the poll job) and cached for ten minutes. Only the handful of
 * vanilla stats below are read; every other (including modded) id is ignored.
 */
class PlayerStats
{
    public const CACHE_SECONDS = 600;

    /** A daemon error is retried sooner than a real answer. */
    public const ERROR_CACHE_SECONDS = 60;

    public const STATE_OK = 'ok';

    public const STATE_MISSING = 'missing';

    public const STATE_ERROR = 'error';

    /** Vanilla files are a few KB; heavily modded ones can reach a few hundred. */
    private const MAX_STATS_BYTES = 2_097_152;

    private const MAX_PROPERTIES_BYTES = 65_536;

    /**
     * Stat => [modern ids (1.13+, first match wins), pre-1.13 id]. play_time is in ticks.
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const STATS = [
        'play_time' => [['minecraft:play_time', 'minecraft:play_one_minute'], 'stat.playOneMinute'],
        'deaths' => [['minecraft:deaths'], 'stat.deaths'],
        'mob_kills' => [['minecraft:mob_kills'], 'stat.mobKills'],
        'player_kills' => [['minecraft:player_kills'], 'stat.playerKills'],
        'distance_walked' => [['minecraft:walk_one_cm'], 'stat.walkOneCm'],
        'jumps' => [['minecraft:jump'], 'stat.jump'],
    ];

    /**
     * @return array{state: string, stats: array<string, int|null>|null} play_time in seconds,
     *                                                                   distance_walked in cm
     */
    public function forPlayer(Server $server, Player $player): array
    {
        if ($player->uuid === null || MinecraftQuery::normalizeUuid($player->uuid) !== $player->uuid) {
            return ['state' => self::STATE_MISSING, 'stats' => null];
        }

        $key = "players-plus.stats.$server->id.$player->uuid";
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['state']) && array_key_exists('stats', $cached)) {
            /** @var array{state: string, stats: array<string, int|null>|null} $cached */
            return $cached;
        }

        $result = $this->read($server, $player->uuid);
        Cache::put($key, $result, $result['state'] === self::STATE_ERROR ? self::ERROR_CACHE_SECONDS : self::CACHE_SECONDS);

        return $result;
    }

    /** @return array{state: string, stats: array<string, int|null>|null} */
    private function read(Server $server, string $uuid): array
    {
        // Sizes are checked from the directory listing first: an oversized file is refused
        // (STATE_ERROR, cached 60 s) without being downloaded.
        try {
            try {
                $level = self::levelName(ServerProperties::get(DaemonFiles::read($server, 'server.properties', self::MAX_PROPERTIES_BYTES), 'level-name'));
            } catch (FileNotFoundException) {
                $level = self::levelName(null);
            }

            $content = DaemonFiles::read($server, "$level/stats/$uuid.json", self::MAX_STATS_BYTES);
        } catch (FileNotFoundException) {
            return ['state' => self::STATE_MISSING, 'stats' => null];
        } catch (Throwable) {
            return ['state' => self::STATE_ERROR, 'stats' => null];
        }

        $stats = self::parse($content);

        return $stats === null ? ['state' => self::STATE_MISSING, 'stats' => null] : ['state' => self::STATE_OK, 'stats' => $stats];
    }

    /**
     * The known stats of a stats file, null for each one that is missing or not a sane
     * number; null overall when the content is not a stats file or has none of them.
     *
     * @return array<string, int|null>|null
     */
    public static function parse(string $content): ?array
    {
        $data = json_decode($content, true, 16);
        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        if (array_key_exists('stats', $data)) {
            $custom = is_array($data['stats']) ? ($data['stats']['minecraft:custom'] ?? []) : null;
            if (!is_array($custom) || ($custom !== [] && array_is_list($custom))) {
                return null;
            }
            $lookup = fn (array $ids) => self::firstCount($custom, $ids[0]);
        } else {
            $lookup = fn (array $ids) => self::firstCount($data, [$ids[1]]);
        }

        $stats = [];
        foreach (self::STATS as $stat => $ids) {
            $stats[$stat] = $lookup($ids);
        }
        if (array_filter($stats, fn (?int $value) => $value !== null) === []) {
            return null;
        }
        if ($stats['play_time'] !== null) {
            $stats['play_time'] = intdiv($stats['play_time'], 20);
        }

        return $stats;
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $ids
     */
    private static function firstCount(array $values, array $ids): ?int
    {
        foreach ($ids as $id) {
            $value = $values[$id] ?? null;
            if (is_int($value) && $value >= 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * `level-name` from server.properties is tenant-editable: only a plain relative path is
     * used, anything else falls back to Minecraft's default "world".
     */
    public static function levelName(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 128 || preg_match('/[\\x00-\\x1f\\x7f\\\\:]/', $value) === 1) {
            return 'world';
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'world';
            }
        }

        return $value;
    }
}
