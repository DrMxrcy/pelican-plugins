<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\PlayersPlus\Support\ServerProperties;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether Query is enabled for a server and on which UDP port, from `server.properties`
 * read through the daemon (cached, so polls don't re-read the file every time).
 */
class QueryPortResolver
{
    public const CACHE_SECONDS = 600;

    private const MAX_PROPERTIES_BYTES = 65_536;

    /** The Query port, or null when `enable-query` is not true or the file can't be read. */
    public function forServer(Server $server, int $gamePort): ?int
    {
        // 0 stands for "disabled" because a cached null would look like a miss.
        $port = (int) Cache::remember("players-plus.query-port.$server->id", self::CACHE_SECONDS, function () use ($server, $gamePort) {
            try {
                $content = app(DaemonFileRepository::class)->setServer($server)->getContent('server.properties', self::MAX_PROPERTIES_BYTES);
            } catch (Throwable) {
                return 0;
            }

            return self::fromProperties($content, $gamePort) ?? 0;
        });

        return $port > 0 ? $port : null;
    }

    public static function fromProperties(string $content, int $gamePort): ?int
    {
        if (strtolower(trim((string) ServerProperties::get($content, 'enable-query'))) !== 'true') {
            return null;
        }

        $port = trim((string) ServerProperties::get($content, 'query.port'));

        return ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535 ? (int) $port : $gamePort;
    }
}
