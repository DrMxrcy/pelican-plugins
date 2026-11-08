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
     * @param  array<mixed>  $categories  Modrinth category names; any of them matches (one OR group,
     *                                    never mixed with the loader group, which must always match).
     * @param  array<int, string>  $projectTypes  Modrinth project types, any of which matches. Datapacks pass
     *                                            ['datapack']: many are also mods, and the facet matches them
     *                                            even when the hit's primary project_type says "mod".
     *
     * New options go in as trailing named arguments, so callers pass them by name.
     */
    public static function build(string $loader, ?string $minecraftVersion, bool $hideClientOnly, array $categories = [], array $projectTypes = self::PROJECT_TYPES): string
    {
        $facets = [['categories:'.$loader]];

        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $facets[] = ['versions:'.$minecraftVersion];
        }

        $facets[] = array_map(fn (string $type) => 'project_type:'.$type, $projectTypes === [] ? self::PROJECT_TYPES : array_values($projectTypes));

        if ($hideClientOnly) {
            $facets[] = array_map(fn (string $side) => 'server_side:'.$side, self::SERVER_SIDE_VALUES);
        }

        $categories = self::normalizeCategories($categories);

        if ($categories !== []) {
            $facets[] = array_map(fn (string $category) => 'categories:'.$category, $categories);
        }

        return json_encode($facets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Unique, non-empty, sorted category names, so the same selection always builds the same
     * facets and cache key whatever order it was picked in.
     *
     * @param  array<mixed>  $categories
     * @return array<int, string>
     */
    public static function normalizeCategories(array $categories): array
    {
        $categories = array_unique(array_filter($categories, fn ($category) => is_string($category) && $category !== ''));
        sort($categories);

        return $categories;
    }
}
