<?php

namespace DrMxrcy\ModpacksPlus\Support;

/**
 * Whether a pack version can go onto a server as it is (same loader, same Minecraft
 * version); anything else is "Needs <loader> <version>" (a loader switch, plan 35).
 */
final class VersionMatch
{
    /**
     * Null when installable; otherwise what the version needs, e.g. "Fabric 1.21.1".
     *
     * @param  array{game_versions: array<int, string>, loaders: array<int, string>}  $version
     */
    public static function needs(array $version, ?string $serverMinecraft, ?string $serverLoader): ?string
    {
        $loaders = array_values(array_intersect(array_map('strtolower', $version['loaders']), ServerTarget::LOADERS));
        $loaderOk = $serverLoader !== null && in_array($serverLoader, $loaders, true);
        $versionOk = $serverMinecraft !== null && in_array($serverMinecraft, $version['game_versions'], true);

        if ($loaderOk && $versionOk) {
            return null;
        }

        $loader = $loaderOk ? $serverLoader : ($loaders[0] ?? ($version['loaders'][0] ?? '?'));
        $minecraft = $versionOk ? $serverMinecraft : ($version['game_versions'][0] ?? '?');

        return trim(ServerTarget::loaderName((string) $loader).' '.$minecraft);
    }
}
