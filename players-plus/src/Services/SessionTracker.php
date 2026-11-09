<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Enums\SessionSource;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds sessions by comparing each new sample with what was online before it. The "before"
 * set is the server's open sessions, so a retried or duplicated sample changes nothing.
 *
 * - A player in the sample without an open session gets one, starting at the poll time.
 * - An open session whose player is missing from a complete sample ends at the poll time.
 * - A partial sample (Ping lists fewer names than are online) never ends a session: the
 *   player may just not be in the sample.
 * - offline / unreachable ends every open session at the last online sample.
 * - A gap in polling (or no earlier sample at all) ends open sessions at the last sample
 *   before the gap, so time nobody watched is never counted as playtime.
 *
 * Runs inside the poll job: no network access except what PlayerIdentity needs.
 */
class SessionTracker
{
    public function __construct(private readonly PlayerIdentity $identity) {}

    public function track(Server $server, Sample $sample): void
    {
        DB::transaction(function () use ($server, $sample) {
            // Only the newest sample moves session state; a late, older one must not reopen or close anything.
            $newer = Sample::query()->where('server_id', $server->id)->where('polled_at', '>', $sample->polled_at)->exists();
            if ($newer) {
                return;
            }

            $previous = Sample::query()->where('server_id', $server->id)->where('polled_at', '<', $sample->polled_at)->orderByDesc('polled_at')->first();
            $gapSeconds = 3 * Settings::pollInterval() * 60 + 60;

            if ($previous === null || $sample->polled_at->getTimestamp() - $previous->polled_at->getTimestamp() > $gapSeconds) {
                $this->closeAll($server, $this->lastOnlineBefore($server, $sample));
            }

            if ($sample->status !== SampleStatus::Online) {
                $this->closeAll($server, $this->lastOnlineBefore($server, $sample));

                return;
            }

            $this->applyOnline($server, $sample);
        });
    }

    private function applyOnline(Server $server, Sample $sample): void
    {
        $at = $sample->polled_at;
        $entries = self::entries($sample->players);
        // Ping lists at most 12 names; more online than listed means some are unseen, not gone.
        $partial = $sample->source === 'ping' && (int) $sample->online > count($entries);

        $present = [];
        foreach ($entries as $entry) {
            $player = $this->identity->resolve($server, $entry['name'], $entry['uuid'], $at);
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

        if ($partial) {
            return;
        }

        foreach ($open as $playerId => $session) {
            if (!isset($present[$playerId])) {
                $this->close($session, $at);
            }
        }
    }

    /**
     * Ends every open session of the server at $at, or, without one, at the player's last
     * sighting.
     */
    private function closeAll(Server $server, ?Carbon $at): void
    {
        foreach ($this->openSessions($server) as $session) {
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
    private function openSessions(Server $server): Collection
    {
        return PlayerSession::query()
            ->whereNull('ended_at')
            ->whereIn('player_id', Player::query()->select('id')->where('server_id', $server->id))
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
}
