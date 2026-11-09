<?php

namespace DrMxrcy\PlayersPlus\Support;

use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Sample;

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
            $name = (string) ($player['name'] ?? '');
            if ($name === '') {
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

    public static function isPartial(Sample $sample): bool
    {
        return $sample->status === SampleStatus::Online
            && $sample->source === 'ping'
            && (int) $sample->online > count($sample->players ?? []);
    }

    /** No sample for three poll intervals: the scheduler or the queue worker is probably not running. */
    public static function isStale(Sample $sample): bool
    {
        return $sample->polled_at->lt(now()->subMinutes(3 * Settings::pollInterval() + 1));
    }
}
