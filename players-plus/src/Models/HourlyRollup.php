<?php

namespace DrMxrcy\PlayersPlus\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One server's samples for one UTC hour, written by players-plus:rollup.
 *
 * @property int $id
 * @property int $server_id
 * @property Carbon $hour_start
 * @property int $epoch_hour hour_start / 3600
 * @property float|null $avg_online average over online samples; null if none were online
 * @property int|null $max_online
 * @property int $samples
 * @property int $online_samples
 * @property-read Server $server
 */
class HourlyRollup extends Model
{
    protected $table = 'pp_hourly';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hour_start' => 'datetime',
            'epoch_hour' => 'integer',
            'avg_online' => 'float',
            'max_online' => 'integer',
            'samples' => 'integer',
            'online_samples' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
