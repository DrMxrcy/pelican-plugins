<?php

namespace DrMxrcy\ModrinthPlus\Support;

/**
 * Builds the `facets` query parameter for Modrinth's /search endpoint.
 *
 * Facets are a JSON array of OR groups that are ANDed together. Built with
 * json_encode so loader/version values are always escaped correctly.
 */
final class SearchFacets
{
    /**
     * Server-side values that can run on a server. `unsupported` (client-only) is left out;
     * `unknown` stays in because older projects often never set the field.
     */
    public const SERVER_SIDE_VALUES = ['required', 'optional', 'unknown'];

    /**
     * Multi-loader projects report a single primary project_type (e.g. a mod with Paper builds),
     * so both types stay in one OR group and the loader facet does the scoping.
     */
    public const PROJECT_TYPES = ['mod', 'plugin'];

    /**
     * @param  string  $loader  Modrinth loader name, e.g. "fabric" or "paper".
     * @param  string|null  $minecraftVersion  null (or empty) for proxies, which are not tied to a version.
     * @param  bool  $hideClientOnly  Drop projects whose server_side is "unsupported".
     */
    public static function build(string $loader, ?string $minecraftVersion, bool $hideClientOnly): string
    {
        $facets = [['categories:'.$loader]];

        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $facets[] = ['versions:'.$minecraftVersion];
        }

        $facets[] = array_map(fn (string $type) => 'project_type:'.$type, self::PROJECT_TYPES);

        if ($hideClientOnly) {
            $facets[] = array_map(fn (string $side) => 'server_side:'.$side, self::SERVER_SIDE_VALUES);
        }

        return json_encode($facets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
