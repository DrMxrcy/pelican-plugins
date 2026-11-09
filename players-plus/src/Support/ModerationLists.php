<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Services\MinecraftQuery;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The server's own ban list, operator list and whitelist, read from `banned-players.json`,
 * `ops.json` and `whitelist.json` in the server root through the size-checked daemon reader.
 * The files are tenant-editable, so every entry is validated and every field is optional.
 * Results (including failures) are cached for 30 seconds and forgotten after an action.
 */
final class ModerationLists
{
    public const BANNED = 'banned';

    public const OPS = 'ops';

    public const WHITELIST = 'whitelist';

    public const FILES = [
        self::BANNED => 'banned-players.json',
        self::OPS => 'ops.json',
        self::WHITELIST => 'whitelist.json',
    ];

    public const CACHE_SECONDS = 30;

    /** After an action, reads skip the cache this long, so the server's rewrite shows up. */
    public const CHANGED_SECONDS = 10;

    /** The file was read and parsed. */
    public const STATE_OK = 'ok';

    /** No such file yet: the server writes it on first start, so this is an empty list. */
    public const STATE_MISSING = 'missing';

    /** Unreadable, too large or not a JSON list: shown as an error, never as an empty list. */
    public const STATE_ERROR = 'error';

    /** A list of a few thousand players is ~1 MB at most; anything larger is not one. */
    private const MAX_BYTES = 1_048_576;

    private const MAX_ENTRIES = 5000;

    private const MAX_REASON = 200;

    /**
     * @param  self::BANNED|self::OPS|self::WHITELIST  $list
     * @return array{state: string, entries: list<array{name: string, uuid: string|null, reason: string|null, source: string|null, created: int|null, expires: int|null, level: int|null}>}
     */
    public static function read(Server $server, string $list): array
    {
        $load = function () use ($server, $list) {
            try {
                $content = DaemonFiles::read($server, self::FILES[$list], self::MAX_BYTES);
            } catch (FileNotFoundException) {
                return ['state' => self::STATE_MISSING, 'entries' => []];
            } catch (Throwable) {
                return ['state' => self::STATE_ERROR, 'entries' => []];
            }

            $entries = self::parse($content);

            return $entries === null
                ? ['state' => self::STATE_ERROR, 'entries' => []]
                : ['state' => self::STATE_OK, 'entries' => $entries];
        };

        // Right after an action the file is about to change: a read now would cache the old list.
        /** @var mixed $cached */
        $cached = Cache::has(self::changedKey($server))
            ? $load()
            : Cache::remember(self::cacheKey($server, $list), self::CACHE_SECONDS, $load);

        if (!is_array($cached) || !is_string($cached['state'] ?? null) || !is_array($cached['entries'] ?? null)) {
            return ['state' => self::STATE_ERROR, 'entries' => []];
        }

        /** @var array{state: string, entries: list<array{name: string, uuid: string|null, reason: string|null, source: string|null, created: int|null, expires: int|null, level: int|null}>} $cached */
        return $cached;
    }

    /** Forgets every cached list of $server, so the next read sees the file again. */
    public static function forget(Server $server): void
    {
        foreach (array_keys(self::FILES) as $list) {
            Cache::forget(self::cacheKey($server, $list));
        }
    }

    /**
     * Entries of one list file, or null when it is not a JSON list. Entries without a usable
     * name are skipped; a name that is not a valid Java name is kept for display (Floodgate
     * players, hand edits) but gets no actions.
     *
     * @return list<array{name: string, uuid: string|null, reason: string|null, source: string|null, created: int|null, expires: int|null, level: int|null}>|null
     */
    public static function parse(string $content): ?array
    {
        if (strlen($content) > self::MAX_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $entries = [];
        foreach (array_slice($decoded, 0, self::MAX_ENTRIES) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = self::text($entry['name'] ?? null, 32);
            if ($name === null) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'uuid' => MinecraftQuery::normalizeUuid($entry['uuid'] ?? null),
                'reason' => self::text($entry['reason'] ?? null, self::MAX_REASON),
                'source' => self::text($entry['source'] ?? null, 64),
                'created' => self::date($entry['created'] ?? null),
                'expires' => self::date($entry['expires'] ?? null),
                'level' => is_int($entry['level'] ?? null) ? max(0, min(4, $entry['level'])) : null,
            ];
        }

        usort($entries, fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * Called after a command was sent: forgets the cached lists and reads the files uncached
     * for a few seconds, while the server writes its change.
     */
    public static function markChanged(Server $server): void
    {
        self::forget($server);
        Cache::put(self::changedKey($server), true, self::CHANGED_SECONDS);
    }

    private static function cacheKey(Server $server, string $list): string
    {
        return "players-plus.lists.$server->id.$list";
    }

    private static function changedKey(Server $server): string
    {
        return "players-plus.lists.$server->id.changed";
    }

    /** One line of printable text, capped; null when missing or empty. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', mb_scrub($value, 'UTF-8'));
        $value = trim(mb_substr($value, 0, $max, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * Unix time of a vanilla date ("2024-01-02 03:04:05 +0000"); "forever" and anything
     * unparsable are null. Stored as an int so the cached result holds no objects.
     */
    private static function date(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
