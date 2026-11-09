<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
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
 * - Joins with no session yet are kept pending in the cursor (names and UUIDs only) until a
 *   poll opens a session for them or their leave line arrives; they expire after
 *   PENDING_HOURS and are dropped when the log rotates.
 * - Daemon access (log, usercache) happens before the lock is taken.
 */
class LogBackfill
{
    public const MAX_PENDING = 200;

    public const PENDING_HOURS = 24;

    public function __construct(
        private readonly LogReader $reader,
        private readonly SessionTracker $tracker,
        private readonly PlayerIdentity $identity,
    ) {}

    /**
     * @throws Throwable daemon errors from LogReader; nothing is changed then
     */
    public function scan(Server $server): void
    {
        $cursor = LogCursor::query()->where('server_id', $server->id)->first();
        $chunk = $this->reader->read($server, $cursor);

        $clock = new LogClock($chunk->timezone, $chunk->restarted ? null : $cursor?->clock_at, $chunk->firstDate);
        $events = LogParser::events($chunk->lines, $clock);
        $pending = $chunk->restarted ? [] : self::pending($cursor);

        $usercache = $events === [] && $pending === [] ? [] : $this->identity->usercache($server);

        $this->tracker->locked($server, function () use ($server, $cursor, $chunk, $clock, $events, $pending, $usercache) {
            $current = LogCursor::query()->where('server_id', $server->id)->lockForUpdate()->first();
            if ($current?->offset !== $cursor?->offset || $current?->identity !== $cursor?->identity) {
                return; // Another scan moved the cursor since this one read it.
            }

            if ($chunk->identity === null) {
                // No complete line yet: nothing to remember but the visit.
                $current?->update(['last_scanned_at' => now()]);

                return;
            }

            $pending = $this->merge($server, $events, $pending, $usercache);

            LogCursor::query()->updateOrCreate(['server_id' => $server->id], [
                'identity' => $chunk->identity,
                'size' => $chunk->cursorSize(),
                'file_modified_at' => LogCursor::storable($chunk->modified),
                'offset' => $chunk->end,
                'clock_at' => LogCursor::storable($clock->last()) ?? ($chunk->restarted ? null : $current?->clock_at),
                'pending' => $pending === [] ? null : $pending,
                'last_scanned_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: LogEvent}>  $events
     * @param  array<string, array{name: string, uuid: string|null, at: string}>  $pending
     * @param  array<string, string>  $usercache
     * @return array<string, array{name: string, uuid: string|null, at: string}>
     */
    private function merge(Server $server, array $events, array $pending, array $usercache): array
    {
        // Joins left over from earlier scans: a poll may have opened their session since.
        foreach ($pending as $key => $join) {
            $at = Carbon::parse($join['at']);
            $player = $this->identity->resolve($server, $join['name'], $join['uuid'], $at, $usercache);
            if ($this->tracker->mergeLogJoin($player, $at)) {
                unset($pending[$key]);
            }
        }

        $uuids = [];
        foreach ($events as [$moment, $event]) {
            $key = strtolower($event->name);
            if ($event->type === LogEventType::Uuid) {
                $uuids[$key] = $event->uuid;

                continue;
            }

            $at = Carbon::instance($moment)->setTimezone((string) config('app.timezone', 'UTC'));
            $uuid = $uuids[$key] ?? ($pending[$key]['uuid'] ?? null);
            $player = $this->identity->resolve($server, $event->name, $uuid, $at, $usercache);

            if ($event->type === LogEventType::Join) {
                if ($this->tracker->mergeLogJoin($player, $at)) {
                    unset($pending[$key]);
                } else {
                    $pending[$key] = ['name' => $event->name, 'uuid' => $player->uuid, 'at' => $at->toIso8601String()];
                }

                continue;
            }

            $join = isset($pending[$key]) ? Carbon::parse($pending[$key]['at']) : null;
            unset($pending[$key]);
            $this->tracker->mergeLogLeave($player, $at, $join);
        }

        $expired = now()->subHours(self::PENDING_HOURS);
        $pending = array_filter($pending, fn (array $join) => Carbon::parse($join['at'])->greaterThan($expired));
        uasort($pending, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        return array_slice($pending, -self::MAX_PENDING, null, true);
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
