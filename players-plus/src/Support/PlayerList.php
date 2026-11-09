<?php

namespace DrMxrcy\PlayersPlus\Support;

use Carbon\CarbonInterval;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\MinecraftQuery;

/**
 * View helpers shared by the Players page and the console widget. Everything here reads a
 * stored Sample; nothing contacts the game server or the daemon.
 */
final class PlayerList
{
    /** Ping lists at most this many players; more online means the list is partial. */
    public const PING_SAMPLE_LIMIT = 12;

    public static function avatarUrl(string $name, ?string $uuid): string
    {
        return 'https://mc-heads.net/avatar/' . rawurlencode($uuid ?? $name) . '/32';
    }

    /** @return array<string, array{name: string, uuid: string|null, avatar: string}> keyed by name */
    public static function rows(?Sample $sample): array
    {
        if ($sample?->status !== SampleStatus::Online) {
            return [];
        }

        $rows = [];
        foreach ($sample->players ?? [] as $player) {
            $name = is_array($player) ? ($player['name'] ?? null) : null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $uuid = isset($player['uuid']) && is_string($player['uuid']) ? $player['uuid'] : null;
            $rows[$name] = ['name' => $name, 'uuid' => $uuid, 'avatar' => self::avatarUrl($name, $uuid)];
        }
        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

        return $rows;
    }

    public static function count(Sample $sample): string
    {
        return trans('players-plus::strings.page.count', ['online' => (int) $sample->online, 'max' => (int) $sample->max]);
    }

    /**
     * Valid, de-duplicated entries of a stored sample. Stored JSON came from a game server,
     * so it is checked again here.
     *
     * @return list<array{name: string, uuid: string|null}>
     */
    public static function entries(mixed $players): array
    {
        if (!is_array($players)) {
            return [];
        }

        $entries = [];
        foreach ($players as $player) {
            $name = is_array($player) ? ($player['name'] ?? null) : null;
            if (!is_string($name) || !MinecraftQuery::isPlayerName($name) || isset($entries[$name])) {
                continue;
            }
            $entries[$name] = ['name' => $name, 'uuid' => MinecraftQuery::normalizeUuid($player['uuid'] ?? null)];
            if (count($entries) === MinecraftQuery::MAX_PLAYERS) {
                break;
            }
        }

        return array_values($entries);
    }

    /**
     * The one rule for "this sample does not name everyone who is online": a Ping sample
     * with more online than valid names listed (the 12-name cap, hidden or anonymous
     * players, names that failed validation). Sessions are neither opened nor closed for
     * the unseen players, and the All players tab says tracking is partial.
     */
    public static function isIncomplete(Sample $sample): bool
    {
        return $sample->status === SampleStatus::Online
            && $sample->source === 'ping'
            && (int) $sample->online > count(self::entries($sample->players));
    }

    /**
     * Whether open sessions may be shown as "online" and counted up to now: the newest
     * sample says online and is not stale. Otherwise an open session counts only up to the
     * player's last sighting.
     */
    public static function isLive(?Sample $sample): bool
    {
        return $sample !== null && $sample->status === SampleStatus::Online && !self::isStale($sample);
    }

    /**
     * The Online tab's "Ping lists at most 12, enable Query" hint (plan 25): only when the
     * cap itself is why names are missing, so Floodgate names dropped by validation do not
     * trigger it. Session tracking uses isIncomplete() instead.
     */
    public static function isPartial(Sample $sample): bool
    {
        // Ping's sample is capped by the server, so more online than the cap means names are
        // missing. Names dropped for other reasons (e.g. invalid) don't trigger this hint.
        return $sample->status === SampleStatus::Online
            && $sample->source === 'ping'
            && (int) $sample->online > self::PING_SAMPLE_LIMIT;
    }

    /** Short, localized playtime such as "2h 30m"; at most two units. */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return CarbonInterval::minutes(0)->forHumans(['short' => true]);
        }

        return CarbonInterval::seconds(intdiv($seconds, 60) * 60)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /** No sample for three poll intervals: the scheduler or the queue worker is probably not running. */
    public static function isStale(Sample $sample): bool
    {
        return $sample->polled_at->lt(now()->subMinutes(3 * Settings::pollInterval() + 1));
    }
}
