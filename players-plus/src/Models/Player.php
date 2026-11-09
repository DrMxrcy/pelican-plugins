<?php

namespace DrMxrcy\PlayersPlus\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One player on one server.
 *
 * @property int $id
 * @property int $server_id
 * @property string|null $uuid dashed lowercase, null while only the name is known
 * @property string $name
 * @property list<string>|null $previous_names oldest first
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $total_seconds closed sessions only
 * @property-read Server $server
 * @property-read Collection<int, PlayerSession> $sessions
 */
class Player extends Model
{
    protected $table = 'pp_players';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'previous_names' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'total_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<PlayerSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(PlayerSession::class);
    }

    public function openSession(): ?PlayerSession
    {
        return $this->sessions()->whereNull('ended_at')->orderBy('started_at')->first();
    }

    /**
     * Closed sessions plus the open one, counted up to $openUntil: now while polling is live,
     * else the player's last sighting (see PlayerList::isLive()).
     */
    public function playtime(Carbon $openUntil): int
    {
        return $this->total_seconds + ($this->openSession()?->seconds($openUntil) ?? 0);
    }

    /** Seconds online between $since and $now, from the sessions overlapping that window; open sessions count up to $now. */
    public function playtimeSince(Carbon $since, ?Carbon $now = null): int
    {
        $now ??= now();
        $seconds = 0;

        $sessions = $this->sessions()
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $since))
            ->where('started_at', '<', $now)
            ->get(['started_at', 'ended_at']);
        foreach ($sessions as $session) {
            $start = max($session->started_at->getTimestamp(), $since->getTimestamp());
            $end = min(($session->ended_at ?? $now)->getTimestamp(), $now->getTimestamp());
            $seconds += max(0, $end - $start);
        }

        return $seconds;
    }
}
