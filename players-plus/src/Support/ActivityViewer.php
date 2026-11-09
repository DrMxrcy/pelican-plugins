<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Models\Server;
use Filament\Facades\Filament;

/** Builds ActivityStats for the current tenant server in the viewing user's timezone. */
final class ActivityViewer
{
    public static function stats(): ?ActivityStats
    {
        $server = Filament::getTenant();
        if (!$server instanceof Server) {
            return null;
        }

        return new ActivityStats($server->id, self::timezone());
    }

    public static function timezone(): string
    {
        $timezone = user()?->timezone;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
