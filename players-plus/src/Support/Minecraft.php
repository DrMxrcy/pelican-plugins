<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;

final class Minecraft
{
    public const TAG = 'minecraft';

    /** A Minecraft server is one whose egg has the `minecraft` tag (the Modrinth Plus rule). */
    public static function isMinecraft(Server $server): bool
    {
        $server->loadMissing('egg');

        return in_array(self::TAG, $server->egg->tags ?? [], true);
    }

    /**
     * Every Minecraft server, by the same rule as isMinecraft(), as one query: the egg filter is
     * a subquery, so callers can paginate it or use ->select('id') as a server-id subquery.
     *
     * @return Builder<Server>
     */
    public static function serversQuery(): Builder
    {
        return Server::query()->whereIn('egg_id', Egg::query()->whereJsonContains('tags', self::TAG)->select('id'));
    }
}
