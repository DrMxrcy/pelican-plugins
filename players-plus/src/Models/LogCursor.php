<?php

namespace DrMxrcy\PlayersPlus\Models;

use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far a server's logs/latest.log has been read (plan 28).
 *
 * @property int $id
 * @property int $server_id
 * @property string|null $identity sha256 of the log's first line
 * @property int $size file size at the last scan
 * @property Carbon|null $file_modified_at file modification time at the last scan
 * @property int $offset byte offset of the first unparsed line
 * @property string|null $tail sha256 of the line ending at the offset
 * @property Carbon|null $clock_at moment of the last timestamped line parsed
 * @property array<string, mixed>|null $pending joins waiting for their leave: lower-case name => {name, at, uuid}
 * @property Carbon|null $last_scanned_at
 * @property-read Server $server
 */
class LogCursor extends Model
{
    protected $table = 'pp_log_cursors';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'offset' => 'integer',
            'file_modified_at' => 'datetime',
            'clock_at' => 'datetime',
            'pending' => 'array',
            'last_scanned_at' => 'datetime',
        ];
    }

    /**
     * A moment in the app's zone, ready to store. Datetime columns keep the wall-clock time
     * without its offset, so a daemon time with its own offset must be converted first.
     */
    public static function storable(?CarbonInterface $at): ?Carbon
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
