<?php

namespace DrMxrcy\ModpacksPlus\Support;

use App\Models\Egg;
use App\Models\Server;

/**
 * What a server runs, read from the egg tags (`minecraft` + a loader tag, the same
 * convention Modrinth Plus uses) and the MINECRAFT_VERSION / MC_VERSION variable.
 * The official pelican-eggs loader eggs carry only the `minecraft` tag (older exports
 * none at all), so they are also recognised by uuid / update_url (EggVariableMap).
 * Unknown (null) whenever it can't be said exactly, e.g. a version of `latest`.
 */
final class ServerTarget
{
    public const LOADERS = ['fabric', 'forge', 'neoforge', 'quilt'];

    public static function isMinecraft(Server $server): bool
    {
        $server->loadMissing('egg');

        return self::isMinecraftEgg($server->egg);
    }

    public static function isMinecraftEgg(Egg $egg): bool
    {
        return in_array('minecraft', array_map(fn ($tag) => strtolower((string) $tag), $egg->tags ?? []), true)
            || EggVariableMap::loaderFor($egg->uuid, $egg->update_url) !== null;
    }

    public static function loader(Server $server): ?string
    {
        if (!self::isMinecraft($server)) {
            return null;
        }

        return self::eggLoader($server->egg);
    }

    /**
     * The loader an egg runs: its loader tag (with `minecraft`), else the official-egg
     * table. Null when unknown or when the two disagree.
     */
    public static function eggLoader(Egg $egg): ?string
    {
        $tagged = self::taggedLoader($egg);
        $official = EggVariableMap::loaderFor($egg->uuid, $egg->update_url);

        if ($tagged !== null && $official !== null && $tagged !== $official) {
            return null;
        }

        return $tagged ?? $official;
    }

    /** The one loader tag of an egg tagged `minecraft`, else null. */
    public static function taggedLoader(Egg $egg): ?string
    {
        $tags = array_map(fn ($tag) => strtolower((string) $tag), $egg->tags ?? []);
        if (!in_array('minecraft', $tags, true)) {
            return null;
        }

        $loaders = array_values(array_intersect(self::LOADERS, $tags));

        return count($loaders) === 1 ? $loaders[0] : null;
    }

    public static function minecraftVersion(Server $server): ?string
    {
        if (!self::isMinecraft($server)) {
            return null;
        }

        $variable = $server->variables()
            ->where(fn ($query) => $query->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))
            ->first();

        $version = $variable === null ? null : ($variable->server_value ?? $variable->default_value);
        $version = is_string($version) ? trim($version) : '';

        if ($version === '' || strtolower($version) === 'latest' || !preg_match('/^[A-Za-z0-9.\-_+]{1,64}$/', $version)) {
            return null;
        }

        return $version;
    }

    /** Whether a pack for $minecraftVersion on $loader can go onto this server as it is. */
    public static function matches(Server $server, string $minecraftVersion, string $loader): bool
    {
        $serverVersion = self::minecraftVersion($server);
        $serverLoader = self::loader($server);

        return $serverVersion !== null && $serverLoader !== null
            && $serverVersion === $minecraftVersion && $serverLoader === strtolower($loader);
    }
}
