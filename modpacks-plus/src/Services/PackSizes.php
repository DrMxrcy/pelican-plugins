<?php

namespace DrMxrcy\ModpacksPlus\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Whole-pack sizes found while preparing installs (plan 38), so the Modpacks page can show
 * a pack version's real size and disable Install when it can't fit. Sizes come from the
 * validated plan (pack index / provider metadata), never from the browser.
 */
class PackSizes
{
    private const TTL_SECONDS = 7 * 24 * 3600;

    public static function remember(string $provider, string $packId, string $versionId, int $bytes): void
    {
        if ($bytes >= 0) {
            Cache::put(self::key($provider, $packId, $versionId), $bytes, self::TTL_SECONDS);
        }
    }

    public static function known(string $provider, string $packId, string $versionId): ?int
    {
        $bytes = Cache::get(self::key($provider, $packId, $versionId));

        return is_int($bytes) && $bytes >= 0 ? $bytes : null;
    }

    private static function key(string $provider, string $packId, string $versionId): string
    {
        return 'modpacks-plus:pack-bytes:'.sha1($provider."\0".$packId."\0".$versionId);
    }
}
