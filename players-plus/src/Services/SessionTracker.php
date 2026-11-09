<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use Closure;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Enums\SessionSource;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds sessions by comparing each new sample with what was online before it. The "before"
 * set is the server's open sessions, so a retried or duplicated sample changes nothing.
 *
 * - A player in the sample without an open session gets one, starting at the poll time.
 * - An open session whose player is missing from a complete sample ends at the poll time.
 * - An incomplete sample (PlayerList::isIncomplete) never ends a session: the player may
 *   just not be in the sample.
 * - offline / unreachable ends every open session at the last online sample.
 * - A gap in polling (or no earlier sample at all) ends sessions opened before this sample
 *   at the last sample before the gap, so time nobody watched is never counted as playtime.
 *
 * Runs inside the poll job. The only network access is the (cached) usercache read, done
 * before the lock and the transaction are taken.
 *
 * Log merge API (plan 28; call inside locked()): join and leave lines carry exact times and
 * refine these sessions without ever counting a second twice.
 * - mergeLogJoin(): a session starting up to overrideWindow() after the join starts at the
 *   join instead (never before the player's previous session ended).
 * - mergeLogLeave(): an open session started before the leave ends at it; a closed one ending
 *   up to overrideWindow() after the leave ends at the leave. A join and leave nothing polled
 *   become a closed session with source `log`, unless any session overlaps them.
 * - Both are idempotent: a session already starting (ending) at the event time is left alone.
 */
class SessionTracker
{
    /** How long a run waits for another run on the same server before giving up. */
    public int $lockWaitSeconds = 10;

    public function __construct(private readonly PlayerIdentity $identity) {}

    public function track(Server $server, Sample $sample): void
    {
        $entries = $sample->status === SampleStatus::Online ? PlayerList::entries($sample->players) : [];
        $usercache = in_array(null, array_column($entries, 'uuid'), true) ? $this->identity->usercache($server) : [];

        // Another run still busy: the next sample re-derives the state from the open sessions.
        $this->locked($server, fn () => $this->apply($server, $sample, $entries, $usercache));
    }

    /**
     * Runs $work in one transaction under the server's tracking lock: one run per server at a
     * time, so two runs (polls or log scans) can't both open or move a session for one player.
     * False, without running it, when the lock is not free within $waitSeconds (default
     * $lockWaitSeconds).
     */
    public function locked(Server $server, Closure $work, ?int $waitSeconds = null): bool
    {
        try {
            Cache::lock("players-plus.track.$server->id", 60)->block(
                $waitSeconds ?? $this->lockWaitSeconds,
                fn () => DB::transaction(fn () => $work()),
            );
        } catch (LockTimeoutException) {
            return false;
        }

        return true;
    }

    /** How far a log time may be from a poll-built start or end and still replace it. */
    public static function overrideWindow(): int
    {
        return 2 * Settings::pollInterval() * 60;
    }

    /**
     * A `joined the game` line at $at. True when it is accounted for (a session now starts at
     * it, or already covers it); false when no session matches yet, so the caller keeps it
     * pending until a poll opens one or the leave line arrives.
     */
    public function mergeLogJoin(Player $player, Carbon $at): bool
    {
        if ($player->sessions()->where('started_at', $at)->exists()) {
            return true;
        }

        $session = $player->sessions()
            ->where('started_at', '>', $at)
            ->where('started_at', '<=', $at->copy()->addSeconds(self::overrideWindow()))
            ->orderBy('started_at')
            ->first();
        if ($session !== null) {
            // Never reach back into the previous session.
            $previousEnd = $player->sessions()->where('started_at', '<', $session->started_at)->whereNotNull('ended_at')->max('ended_at');
            $start = $previousEnd === null ? $at->copy() : $at->copy()->max(Carbon::parse($previousEnd));
            if ($start->lessThan($session->started_at)) {
                $moved = PlayerSession::query()->whereKey($session->id)->where('started_at', $session->started_at)
                    ->update(['started_at' => $start, 'source' => SessionSource::Log]);
                if ($moved === 1 && $session->ended_at !== null) {
                    Player::query()->whereKey($player->id)->increment('total_seconds', $session->started_at->getTimestamp() - $start->getTimestamp());
                }
                Player::query()->whereKey($player->id)->where('first_seen_at', '>', $start)->update(['first_seen_at' => $start]);
            }

            return true;
        }

        return $player->sessions()
            ->where('started_at', '<', $at)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->exists();
    }

    /**
     * A `left the game` line at $at, with the pending join it closes (if one is waiting).
     */
    public function mergeLogLeave(Player $player, Carbon $at, ?Carbon $join = null): void
    {
        if ($player->sessions()->where('ended_at', $at)->exists()) {
            return;
        }

        if ($join !== null && $join->lessThan($at)) {
            $overlaps = $player->sessions()
                ->where('started_at', '<', $at)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $join))
                ->exists();
            if (!$overlaps) {
                PlayerSession::query()->create([
                    'player_id' => $player->id,
                    'started_at' => $join,
                    'ended_at' => $at,
                    'source' => SessionSource::Log,
                ]);
                Player::query()->whereKey($player->id)->increment('total_seconds', $at->getTimestamp() - $join->getTimestamp());
                Player::query()->whereKey($player->id)->where('first_seen_at', '>', $join)->update(['first_seen_at' => $join]);
                Player::query()->whereKey($player->id)->where('last_seen_at', '<', $at)->update(['last_seen_at' => $at]);

                return;
            }
        }

        $open = $player->sessions()->whereNull('ended_at')->where('started_at', '<=', $at)->orderBy('started_at')->first();
        if ($open !== null) {
            $this->close($open, $at, SessionSource::Log);

            return;
        }

        $session = $player->sessions()
            ->where('started_at', '<=', $at)
            ->where('ended_at', '>', $at)
            ->where('ended_at', '<=', $at->copy()->addSeconds(self::overrideWindow()))
            ->orderBy('ended_at')
            ->first();
        if ($session?->ended_at === null) {
            return;
        }

        $moved = PlayerSession::query()->whereKey($session->id)->where('ended_at', $session->ended_at)
            ->update(['ended_at' => $at, 'source' => SessionSource::Log]);
        if ($moved === 1) {
            Player::query()->whereKey($player->id)->decrement('total_seconds', $session->ended_at->getTimestamp() - $at->getTimestamp());
            // The poll that found the player gone is not a sighting; the leave line is.
            Player::query()->whereKey($player->id)->where('last_seen_at', $session->ended_at)->update(['last_seen_at' => $at]);
        }
    }

    /**
     * @param  list<array{name: string, uuid: string|null}>  $entries
     * @param  array<string, string>  $usercache
     */
    private function apply(Server $server, Sample $sample, array $entries, array $usercache): void
    {
        // Only the newest sample moves session state; a late, older one must not reopen or close anything.
        $newer = Sample::query()->where('server_id', $server->id)->where('polled_at', '>', $sample->polled_at)->exists();
        if ($newer) {
            return;
        }

        $previous = Sample::query()->where('server_id', $server->id)->where('polled_at', '<', $sample->polled_at)->orderByDesc('polled_at')->first();
        $gapSeconds = 3 * Settings::pollInterval() * 60 + 60;

        if ($previous === null || $sample->polled_at->getTimestamp() - $previous->polled_at->getTimestamp() > $gapSeconds) {
            // Sessions this very sample opened (a retry) are not part of the gap.
            $this->closeAll($server, $this->lastOnlineBefore($server, $sample), $sample->polled_at);
        }

        if ($sample->status !== SampleStatus::Online) {
            $this->closeAll($server, $this->lastOnlineBefore($server, $sample));

            return;
        }

        $at = $sample->polled_at;
        $present = [];
        foreach ($entries as $entry) {
            $player = $this->identity->resolve($server, $entry['name'], $entry['uuid'], $at, $usercache);
            $present[$player->id] = true;
        }

        $open = $this->openSessions($server)->keyBy('player_id');

        foreach (array_keys($present) as $playerId) {
            if (!$open->has($playerId)) {
                PlayerSession::query()->create([
                    'player_id' => $playerId,
                    'started_at' => $at,
                    'source' => SessionSource::Poll,
                ]);
            }
        }

        if (PlayerList::isIncomplete($sample)) {
            return;
        }

        foreach ($open as $playerId => $session) {
            if (!isset($present[$playerId])) {
                $this->close($session, $at);
            }
        }
    }

    /**
     * Ends open sessions of the server at $at, or, without one, at the player's last
     * sighting. With $startedBefore, sessions starting at or after it are left open.
     */
    private function closeAll(Server $server, ?Carbon $at, ?Carbon $startedBefore = null): void
    {
        foreach ($this->openSessions($server, $startedBefore) as $session) {
            $this->close($session, $at ?? $session->player->last_seen_at);
        }
    }

    private function close(PlayerSession $session, Carbon $at, ?SessionSource $source = null): void
    {
        $end = $at->greaterThan($session->started_at) ? $at->copy() : $session->started_at->copy();

        // Conditional update: a session already closed elsewhere is not counted twice.
        $closed = PlayerSession::query()->whereKey($session->id)->whereNull('ended_at')
            ->update(['ended_at' => $end] + ($source === null ? [] : ['source' => $source]));
        if ($closed !== 1) {
            return;
        }

        Player::query()->whereKey($session->player_id)->increment('total_seconds', $end->getTimestamp() - $session->started_at->getTimestamp());
        Player::query()->whereKey($session->player_id)->where('last_seen_at', '<', $end)->update(['last_seen_at' => $end]);
    }

    /** @return Collection<int, PlayerSession> */
    private function openSessions(Server $server, ?Carbon $startedBefore = null): Collection
    {
        return PlayerSession::query()
            ->whereNull('ended_at')
            ->whereIn('player_id', Player::query()->select('id')->where('server_id', $server->id))
            ->when($startedBefore !== null, fn ($query) => $query->where('started_at', '<', $startedBefore))
            ->with('player')
            ->get();
    }

    private function lastOnlineBefore(Server $server, Sample $sample): ?Carbon
    {
        return Sample::query()
            ->where('server_id', $server->id)
            ->where('status', SampleStatus::Online)
            ->where('polled_at', '<', $sample->polled_at)
            ->orderByDesc('polled_at')
            ->first()
            ?->polled_at;
    }
}
