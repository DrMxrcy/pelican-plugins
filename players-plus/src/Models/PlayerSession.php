<?php

namespace DrMxrcy\PlayersPlus\Models;

use DrMxrcy\PlayersPlus\Enums\SessionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of time a player was online on a server.
 *
 * @property int $id
 * @property int $player_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at null while the player is online
 * @property SessionSource $source
 * @property-read Player $player
 */
class PlayerSession extends Model
{
    protected $table = 'pp_sessions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'source' => SessionSource::class,
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Length in seconds; an open session counts up to $now. */
    public function seconds(?Carbon $now = null): int
    {
        $end = $this->ended_at ?? $now ?? now();

        return max(0, $end->getTimestamp() - $this->started_at->getTimestamp());
    }
}
