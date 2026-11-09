<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Support\DaemonFiles;
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

    /**
     * The Query port, or null when `enable-query` is not true, the file can't be read, or
     * `query.port` is not one of this server's own allocation ports on the polled IP.
     * server.properties is tenant-editable, so an arbitrary port would let a server owner
     * make the panel send UDP packets anywhere on the node.
     */
    public function forServer(Server $server, int $gamePort): ?int
    {
        // 0 stands for "disabled" because a cached null would look like a miss.
        $port = (int) Cache::remember("players-plus.query-port.$server->id", self::CACHE_SECONDS, function () use ($server, $gamePort) {
            try {
                $content = DaemonFiles::read($server, 'server.properties', self::MAX_PROPERTIES_BYTES);
            } catch (Throwable) {
                return 0;
            }

            return self::fromProperties($content, $gamePort, self::allowedPorts($server, $gamePort)) ?? 0;
        });

        return $port > 0 ? $port : null;
    }

    /**
     * Ports of the server's allocations on the same IP as its primary allocation (the IP the
     * poll targets), always including the game port.
     *
     * @return list<int>
     */
    public static function allowedPorts(Server $server, int $gamePort): array
    {
        $ip = $server->allocation?->ip;
        $ports = $ip === null ? [] : $server->allocations()->where('ip', $ip)->pluck('port')->map(fn ($port) => (int) $port)->all();
        $ports[] = $gamePort;

        return array_values(array_unique($ports));
    }

    /**
     * @param  list<int>  $allowedPorts  a `query.port` outside this list disables Query
     */
    public static function fromProperties(string $content, int $gamePort, array $allowedPorts): ?int
    {
        if (strtolower(trim((string) ServerProperties::get($content, 'enable-query'))) !== 'true') {
            return null;
        }

        $value = trim((string) ServerProperties::get($content, 'query.port'));
        if ($value === '') {
            // Minecraft's default query.port is the game port.
            return $gamePort;
        }

        if (!ctype_digit($value) || strlen($value) > 5) {
            return null;
        }

        return in_array((int) $value, $allowedPorts, true) ? (int) $value : null;
    }
}
