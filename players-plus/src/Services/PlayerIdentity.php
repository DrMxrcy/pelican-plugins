<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Models\Player;
use Illuminate\Support\Carbon;

/**
 * Turns a name (and UUID, when the sample has one) into this server's Player record.
 */
class PlayerIdentity
{
    public function resolve(Server $server, string $name, ?string $uuid, Carbon $seenAt): Player
    {
        $player = $uuid !== null
            ? Player::query()->where('server_id', $server->id)->where('uuid', $uuid)->first()
            : Player::query()->where('server_id', $server->id)->where('name', $name)->orderByDesc('last_seen_at')->first();

        if ($player === null) {
            return Player::query()->create([
                'server_id' => $server->id,
                'uuid' => $uuid,
                'name' => $name,
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
            ]);
        }

        if ($player->last_seen_at->lt($seenAt)) {
            $player->last_seen_at = $seenAt;
        }
        $player->save();

        return $player;
    }
}
