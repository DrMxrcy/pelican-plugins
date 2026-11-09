<?php

namespace DrMxrcy\PlayersPlus\Models;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One poll of one server.
 *
 * @property int $id
 * @property int $server_id
 * @property Carbon $polled_at
 * @property SampleStatus $status
 * @property int|null $online
 * @property int|null $max
 * @property list<array{name: string, uuid: string|null}>|null $players
 * @property string|null $source query | ping
 * @property-read Server $server
 */
class Sample extends Model
{
    protected $table = 'pp_samples';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'polled_at' => 'datetime',
            'status' => SampleStatus::class,
            'online' => 'integer',
            'max' => 'integer',
            'players' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The newest stored sample for a server; pages render from this, never from a live query. */
    public static function latestFor(Server $server): ?self
    {
        return static::query()->where('server_id', $server->id)->orderByDesc('polled_at')->first();
    }
}
