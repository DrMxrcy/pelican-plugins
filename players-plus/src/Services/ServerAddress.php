<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;

/**
 * Where the panel reaches a server's game port: the allocation alias when one is set (and is
 * not a wildcard), else the allocation IP, else the node FQDN when the IP is 0.0.0.0 / ::.
 */
final class ServerAddress
{
    private const UNSPECIFIED = ['', '0.0.0.0', '::'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {}

    public static function forServer(Server $server): ?self
    {
        $allocation = $server->allocation;
        if ($allocation === null) {
            return null;
        }

        return new self(
            self::pickHost($allocation->ip_alias, (string) $allocation->ip, (string) $server->node->fqdn),
            (int) $allocation->port,
        );
    }

    /** The host to connect to, never bracketed. */
    public static function pickHost(?string $alias, string $ip, string $fqdn): string
    {
        foreach ([$alias, $ip] as $candidate) {
            $host = self::unbracket((string) $candidate);
            if (!in_array($host, self::UNSPECIFIED, true)) {
                return $host;
            }
        }

        return self::unbracket($fqdn);
    }

    /** scheme://host:port with an IPv6 literal in brackets. */
    public static function uri(string $scheme, string $host, int $port): string
    {
        $host = self::unbracket($host);

        return $scheme . '://' . (str_contains($host, ':') ? "[$host]" : $host) . ':' . $port;
    }

    private static function unbracket(string $host): string
    {
        $host = trim($host);

        return str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
    }
}
