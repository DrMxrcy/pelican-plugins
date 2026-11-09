<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Models\Server;

final class Minecraft
{
    /** A Minecraft server is one whose egg has the `minecraft` tag (the Modrinth Plus rule). */
    public static function isMinecraft(Server $server): bool
    {
        $server->loadMissing('egg');

        return in_array('minecraft', $server->egg->tags ?? [], true);
    }
}
