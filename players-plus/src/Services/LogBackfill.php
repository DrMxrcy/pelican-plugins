<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Closure;
use DrMxrcy\PlayersPlus\Enums\LogEventType;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One log scan for one server: read the next part of logs/latest.log (LogReader), date and
 * parse it (LogClock, LogParser), then, under the server's tracking lock and in one
 * transaction, merge the events into sessions (SessionTracker) and move the cursor.
 *
 * - The cursor and the sessions change together, so a failed or retried scan re-applies
 *   nothing twice; the merge itself is idempotent per (player, type, time) as well.
 * - Bounded work per scan, whatever the log holds: at most MAX_EVENTS join/leave lines, at most
 *   MAX_NEW_PLAYERS new player records and MERGE_SECONDS of merging. Hitting a limit ends the
 *   scan at that line; the next scan continues after it.
 * - Log times are never trusted past now + FUTURE_SLACK_SECONDS (the clock is capped there) or
 *   before now - MAX_AGE_DAYS (dropped).
 * - Only a join paired with its leave creates a player record. A lone join of an unknown player
 *   waits in the cursor (names and UUIDs only) until a poll creates the player or the leave
 *   arrives; a lone leave of an unknown player is ignored. Pending joins expire after
 *   PENDING_HOURS and are dropped when the log rotates.
 * - Daemon access (log, usercache) happens before the lock is taken; the usercache only when
 *   a name has no UUID from the log itself.
 */
class LogBackfill
{
    public const MAX_PENDING = 200;

    public const PENDING_HOURS = 24;

    public const MAX_EVENTS = 500;

    public const MAX_NEW_PLAYERS = 100;

    public const MERGE_SECONDS = 10;

    public const FUTURE_SLACK_SECONDS = 120;

    public const MAX_AGE_DAYS = 400;

    /** @var Closure(): float seconds, monotonic; replaceable in tests */
    public Closure $clock;

    /** A scan waits at most this long for a poll holding the tracking lock. */
    public int $lockWaitSeconds = 5;

    public function __construct(
        private readonly LogReader $reader,
        private readonly SessionTracker $tracker,
        private readonly PlayerIdentity $identity,
    ) {
        $this->clock = fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @throws LogReadTooSlow
     * @throws Throwable daemon errors from LogReader; nothing is changed then
     */
    public function scan(Server $server): void
    {
        $cursor = LogCursor::query()->where('server_id', $server->id)->first();
        $chunk = $this->reader->read($server, $cursor);

        $ceiling = now()->addSeconds(self::FUTURE_SLACK_SECONDS);
        $floor = now()->subDays(self::MAX_AGE_DAYS);
        $clock = new LogClock($chunk->timezone, $chunk->restarted ? null : $cursor?->clock_at, $chunk->firstDate, $ceiling);

        // Join/leave/UUID lines with their line and the clock after them, up to MAX_EVENTS.
        $events = [];
        $moves = 0;
        $cutLine = null;
        foreach ($chunk->lines as $index => $raw) {
            $line = LogParser::line($raw);
            if ($line === null) {
                continue;
            }
            $at = $clock->at($line); // every timestamped line moves the clock, events or not
            if ($at === null || $line->event === null || $at->lessThan($floor) || $at->greaterThan($ceiling)) {
                continue;
            }
            $events[] = ['line' => $index, 'at' => $at, 'event' => $line->event, 'clock' => $clock->last()];
            if ($line->event->type !== LogEventType::Uuid && ++$moves >= self::MAX_EVENTS) {
                $cutLine = $index;
                break;
            }
        }
        $clockEnd = $clock->last();

        $pending = $chunk->restarted ? [] : self::pending($cursor);
        $usercache = self::needsUsercache($events, $pending) ? $this->identity->usercache($server) : [];

        $this->tracker->locked($server, function () use ($server, $cursor, $chunk, $events, $pending, $usercache, $cutLine, $clockEnd) {
            $current = LogCursor::query()->where('server_id', $server->id)->lockForUpdate()->first();
            if ($current?->offset !== $cursor?->offset || $current?->identity !== $cursor?->identity) {
                return; // Another scan moved the cursor since this one read it.
            }

            if ($chunk->identity === null) {
                // No complete line yet: remember the size, so an unchanged file is not fetched again.
                LogCursor::query()->updateOrCreate(['server_id' => $server->id], [
                    'identity' => null, 'size' => $chunk->size, 'file_modified_at' => LogCursor::storable($chunk->modified),
                    'offset' => 0, 'tail' => null, 'clock_at' => null, 'pending' => null, 'last_scanned_at' => now(),
                ]);

                return;
            }

            [$pending, $stopped] = $this->merge($server, $events, $pending, $usercache);
            $cutClock = null;
            if ($stopped !== null && $stopped < count($events) - 1) {
                $cutLine = $events[$stopped]['line'];
                $cutClock = $events[$stopped]['clock'];
            } elseif ($cutLine !== null) {
                $cutClock = $events[count($events) - 1]['clock'];
            }

            $values = $cutLine === null
                ? [
                    'size' => $chunk->cursorSize(),
                    'offset' => $chunk->end,
                    'tail' => $chunk->tail,
                    'clock_at' => LogCursor::storable($clockEnd) ?? ($chunk->restarted ? null : $current?->clock_at),
                ]
                : [
                    'size' => 0, // lines are left: the next scan must read on
                    'offset' => $chunk->lineEnds[$cutLine],
                    'tail' => hash('sha256', $chunk->lines[$cutLine]),
                    'clock_at' => LogCursor::storable($cutClock),
                ];

            LogCursor::query()->updateOrCreate(['server_id' => $server->id], $values + [
                'identity' => $chunk->identity,
                'file_modified_at' => LogCursor::storable($chunk->modified),
                'pending' => $pending === [] ? null : $pending,
                'last_scanned_at' => now(),
            ]);
        }, $this->lockWaitSeconds);
    }

    /**
     * @param  list<array{line: int, at: CarbonImmutable, event: LogEvent, clock: CarbonImmutable|null}>  $events
     * @param  array<string, array{name: string, uuid: string|null, at: string}>  $pending
     * @param  array<string, string>  $usercache
     * @return array{0: array<string, array{name: string, uuid: string|null, at: string}>, 1: int|null}
     *                                                                                                  pending joins, and the index of the event the merge stopped after (null: all done)
     */
    private function merge(Server $server, array $events, array $pending, array $usercache): array
    {
        $started = ($this->clock)();
        $created = 0;

        // Joins left over from earlier scans: a poll may have opened their session since.
        foreach ($pending as $key => $join) {
            if (!$this->identity->known($server, $join['name'], $join['uuid'], $usercache)) {
                continue;
            }
            $at = Carbon::parse($join['at']);
            $player = $this->identity->resolve($server, $join['name'], $join['uuid'], $at, $usercache);
            if ($this->tracker->mergeLogJoin($player, $at)) {
                unset($pending[$key]);
            }
        }

        $uuids = [];
        $stopped = null;
        foreach ($events as $index => ['at' => $moment, 'event' => $event]) {
            $key = strtolower($event->name);
            if ($event->type === LogEventType::Uuid) {
                $uuids[$key] = $event->uuid;

                continue;
            }

            $at = Carbon::instance($moment)->setTimezone((string) config('app.timezone', 'UTC'));
            $uuid = $uuids[$key] ?? ($pending[$key]['uuid'] ?? null);

            if ($event->type === LogEventType::Join) {
                $matched = false;
                if ($this->identity->known($server, $event->name, $uuid, $usercache)) {
                    $player = $this->identity->resolve($server, $event->name, $uuid, $at, $usercache);
                    $uuid = $player->uuid;
                    $matched = $this->tracker->mergeLogJoin($player, $at);
                }
                if ($matched) {
                    unset($pending[$key]);
                } else {
                    $pending[$key] = ['name' => $event->name, 'uuid' => $uuid, 'at' => $at->toIso8601String()];
                }
            } else {
                $join = isset($pending[$key]) ? Carbon::parse($pending[$key]['at']) : null;
                unset($pending[$key]);
                // Only a join paired with a later leave may create a player record.
                if (($join !== null && $join->lessThan($at)) || $this->identity->known($server, $event->name, $uuid, $usercache)) {
                    $player = $this->identity->resolve($server, $event->name, $uuid, $at, $usercache);
                    $created += $player->wasRecentlyCreated ? 1 : 0;
                    $this->tracker->mergeLogLeave($player, $at, $join);
                }
            }

            if ($created >= self::MAX_NEW_PLAYERS || ($this->clock)() - $started >= self::MERGE_SECONDS) {
                $stopped = $index;
                break;
            }
        }

        $expired = now()->subHours(self::PENDING_HOURS);
        $pending = array_filter($pending, fn (array $join) => Carbon::parse($join['at'])->greaterThan($expired));
        uasort($pending, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        return [array_slice($pending, -self::MAX_PENDING, null, true), $stopped];
    }

    /**
     * Whether some join/leave name has no UUID from the log itself (a UUID line before it, or
     * its pending join), so only the usercache can supply one.
     *
     * @param  list<array{line: int, at: CarbonImmutable, event: LogEvent, clock: CarbonImmutable|null}>  $events
     * @param  array<string, array{name: string, uuid: string|null, at: string}>  $pending
     */
    private static function needsUsercache(array $events, array $pending): bool
    {
        foreach ($pending as $join) {
            if ($join['uuid'] === null) {
                return true;
            }
        }

        $uuids = [];
        foreach ($events as ['event' => $event]) {
            $key = strtolower($event->name);
            if ($event->type === LogEventType::Uuid) {
                $uuids[$key] = true;
            } elseif (!isset($uuids[$key]) && ($pending[$key]['uuid'] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cursor's pending joins, validated: the column is ours, but JSON is not trusted.
     *
     * @return array<string, array{name: string, uuid: string|null, at: string}>
     */
    private static function pending(?LogCursor $cursor): array
    {
        $valid = [];
        foreach ($cursor->pending ?? [] as $join) {
            if (!is_array($join) || !is_string($join['name'] ?? null) || !MinecraftQuery::isPlayerName($join['name']) || !is_string($join['at'] ?? null)) {
                continue;
            }
            try {
                $at = Carbon::parse($join['at']);
            } catch (Throwable) {
                continue;
            }
            $valid[strtolower($join['name'])] = [
                'name' => $join['name'],
                'uuid' => MinecraftQuery::normalizeUuid($join['uuid'] ?? null),
                'at' => $at->toIso8601String(),
            ];
        }

        return array_slice($valid, -self::MAX_PENDING, null, true);
    }
}
