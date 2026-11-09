<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
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

        try {
            // One run per server at a time, so two runs can't both open a session for one player.
            Cache::lock("players-plus.track.$server->id", 60)->block(
                $this->lockWaitSeconds,
                fn () => DB::transaction(fn () => $this->apply($server, $sample, $entries, $usercache)),
            );
        } catch (LockTimeoutException) {
            // Another run is still busy; the next sample re-derives the state from the open sessions.
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

    private function close(PlayerSession $session, Carbon $at): void
    {
        $end = $at->greaterThan($session->started_at) ? $at->copy() : $session->started_at->copy();

        // Conditional update: a session already closed elsewhere is not counted twice.
        $closed = PlayerSession::query()->whereKey($session->id)->whereNull('ended_at')->update(['ended_at' => $end]);
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
