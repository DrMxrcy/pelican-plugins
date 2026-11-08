<?php

namespace DrMxrcy\ModrinthPlus\Support;

/**
 * Classifies installed projects against the versions Modrinth offers for the server's
 * current Minecraft version + loader (`getProjectVersionsBulk()`: newest first, `[]` = no
 * compatible build, `null` = the lookup failed). Pure: reads arrays, never touches the
 * server, the metadata file or Modrinth.
 */
final class Compatibility
{
    /** The installed version is built for this Minecraft version + loader. */
    public const COMPATIBLE = 'compatible';

    /** The installed version is not, but another version of the project is. */
    public const NEEDS_UPDATE = 'needs_update';

    /** No version of the project is built for this Minecraft version + loader. */
    public const INCOMPATIBLE = 'incompatible';

    /** Could not tell: the lookup failed, or the server's loader is unknown. */
    public const UNKNOWN = 'unknown';

    /**
     * @param  array<int, mixed>|null  $compatibleVersions  one project's entry from getProjectVersionsBulk()
     */
    public static function classify(string $installedVersionId, ?array $compatibleVersions): string
    {
        if ($compatibleVersions === null) {
            return self::UNKNOWN;
        }

        if ($compatibleVersions === []) {
            return self::INCOMPATIBLE;
        }

        foreach ($compatibleVersions as $version) {
            if (is_array($version) && ($version['id'] ?? null) === $installedVersionId) {
                return self::COMPATIBLE;
            }
        }

        return self::NEEDS_UPDATE;
    }

    /**
     * One report row per installed project, in the given order.
     *
     * Without a loader getProjectVersionsBulk() returns `[]` for every project without
     * asking Modrinth, so `$loaderKnown = false` reports everything as unknown instead of
     * incompatible. A project missing from `$versionsByProject` is unknown too.
     *
     * @param  array<int, array{project_id: string, project_title: string, version_id: string, version_number: string}>  $installedMods
     * @param  array<string, array<int, mixed>|null>  $versionsByProject  getProjectVersionsBulk() result
     * @return list<array{project_id: string, title: string, installed_version: string, status: string, newest_version: ?string}>
     */
    public static function report(array $installedMods, array $versionsByProject, bool $loaderKnown): array
    {
        $rows = [];

        foreach ($installedMods as $mod) {
            $projectId = $mod['project_id'];
            $versions = $loaderKnown && $projectId !== '' ? ($versionsByProject[$projectId] ?? null) : null;
            $status = self::classify($mod['version_id'], $versions);

            $rows[] = [
                'project_id' => $projectId,
                'title' => $mod['project_title'] !== '' ? $mod['project_title'] : $projectId,
                'installed_version' => $mod['version_number'],
                'status' => $status,
                'newest_version' => $status === self::NEEDS_UPDATE ? self::newestVersionNumber($versions ?? []) : null,
            ];
        }

        return $rows;
    }

    /**
     * Report rows that need attention (everything except compatible).
     *
     * @param  list<array{project_id: string, title: string, installed_version: string, status: string, newest_version: ?string}>  $report
     * @return list<array{project_id: string, title: string, installed_version: string, status: string, newest_version: ?string}>
     */
    public static function problems(array $report): array
    {
        return array_values(array_filter($report, fn (array $row) => $row['status'] !== self::COMPATIBLE));
    }

    /** @param  array<int, mixed>  $versions  newest first */
    private static function newestVersionNumber(array $versions): ?string
    {
        $newest = $versions[array_key_first($versions)] ?? null;
        $number = is_array($newest) ? ($newest['version_number'] ?? null) : null;

        return is_string($number) ? $number : null;
    }
}
