<?php

namespace DrMxrcy\ModrinthPlus\Support;

/**
 * Works out which required dependencies a Modrinth version pulls in.
 *
 * Pure: the caller supplies a lookup of compatible versions per project (newest first, already
 * filtered to the server's loader and Minecraft version; `null` when the lookup failed) and the
 * projects already installed. Only `required` dependencies are followed; `optional`, `embedded`
 * and `incompatible` ones are never installed.
 */
final class DependencyResolver
{
    public const REQUIRED = 'required';

    public const INCOMPATIBLE = 'incompatible';

    /** Most dependencies installed for one project, so a runaway graph can't fill the server. */
    public const MAX_DEPENDENCIES = 25;

    /** Only a file name or a bare version id: nothing we can look up by project. */
    public const REASON_MANUAL = 'manual';

    /** Modrinth has no build of the dependency for this loader and Minecraft version. */
    public const REASON_NO_COMPATIBLE_VERSION = 'no_compatible_version';

    /** The version lookup failed (outage, timeout); unknown rather than incompatible. */
    public const REASON_LOOKUP_FAILED = 'lookup_failed';

    /** Not looked at because MAX_DEPENDENCIES was reached. */
    public const REASON_LIMIT = 'limit';

    /**
     * Breadth first, so a dependency always comes after the project that requires it.
     *
     * @param  string  $rootProjectId  the project being installed; never part of the result
     * @param  array<string, mixed>  $version  the version being installed
     * @param  callable(string): (array<int, array<string, mixed>>|null)  $versionsFor  compatible versions of a project, newest first; null when the lookup failed
     * @param  array<int, string>  $installedProjectIds
     * @return array{
     *     install: list<array{project_id: string, version: array<string, mixed>, required_by: string}>,
     *     unresolved: list<array{project_id: ?string, version_id: ?string, file_name: ?string, reason: string, required_by: string}>
     * }
     */
    public static function resolve(string $rootProjectId, array $version, callable $versionsFor, array $installedProjectIds, int $limit = self::MAX_DEPENDENCIES): array
    {
        $install = [];
        $unresolved = [];

        /** @var array<string, true> $visited project ids and manual entries already handled */
        $visited = array_fill_keys(array_map('strval', $installedProjectIds), true);
        $visited[$rootProjectId] = true;

        /** @var list<array{array<string, mixed>, string}> $queue */
        $queue = [[$version, $rootProjectId]];

        while ($queue !== []) {
            [$current, $requiredBy] = array_shift($queue);

            foreach (self::dependencies($current, self::REQUIRED) as $dependency) {
                [$projectId, $versionId, $fileName] = $dependency;

                if ($projectId === null) {
                    $key = $versionId !== null ? "version:$versionId" : "file:$fileName";

                    if (!isset($visited[$key])) {
                        $visited[$key] = true;
                        $unresolved[] = self::unresolved(null, $versionId, $fileName, self::REASON_MANUAL, $requiredBy);
                    }

                    continue;
                }

                if (isset($visited[$projectId])) {
                    continue;
                }

                $visited[$projectId] = true;

                if (count($install) >= $limit) {
                    $unresolved[] = self::unresolved($projectId, $versionId, $fileName, self::REASON_LIMIT, $requiredBy);

                    continue;
                }

                $versions = $versionsFor($projectId);

                if ($versions === null) {
                    $unresolved[] = self::unresolved($projectId, $versionId, $fileName, self::REASON_LOOKUP_FAILED, $requiredBy);

                    continue;
                }

                $chosen = self::choose($versions, $versionId);

                if ($chosen === null) {
                    $unresolved[] = self::unresolved($projectId, $versionId, $fileName, self::REASON_NO_COMPATIBLE_VERSION, $requiredBy);

                    continue;
                }

                $install[] = ['project_id' => $projectId, 'version' => $chosen, 'required_by' => $requiredBy];
                $queue[] = [$chosen, $projectId];
            }
        }

        return ['install' => $install, 'unresolved' => $unresolved];
    }

    /**
     * Installed projects this version declares itself incompatible with.
     *
     * @param  array<string, mixed>  $version
     * @param  array<int, string>  $installedProjectIds
     * @return list<string>
     */
    public static function incompatibleInstalled(array $version, array $installedProjectIds): array
    {
        $installed = array_flip(array_map('strval', $installedProjectIds));
        $result = [];

        foreach (self::dependencies($version, self::INCOMPATIBLE) as [$projectId]) {
            if ($projectId !== null && isset($installed[$projectId]) && !in_array($projectId, $result, true)) {
                $result[] = $projectId;
            }
        }

        return $result;
    }

    /** @param  array<string, mixed>  $version */
    public static function hasRequiredDependencies(array $version): bool
    {
        return self::dependencies($version, self::REQUIRED) !== [];
    }

    /**
     * Well-formed dependency entries of one type as [project_id, version_id, file_name]; entries
     * with none of the three are dropped.
     *
     * @param  array<string, mixed>  $version
     * @return list<array{?string, ?string, ?string}>
     */
    private static function dependencies(array $version, string $type): array
    {
        $dependencies = $version['dependencies'] ?? null;

        if (!is_array($dependencies)) {
            return [];
        }

        $result = [];

        foreach ($dependencies as $dependency) {
            if (!is_array($dependency) || ($dependency['dependency_type'] ?? null) !== $type) {
                continue;
            }

            $projectId = self::string($dependency['project_id'] ?? null);
            $versionId = self::string($dependency['version_id'] ?? null);
            $fileName = self::string($dependency['file_name'] ?? null);

            if ($projectId === null && $versionId === null && $fileName === null) {
                continue;
            }

            $result[] = [$projectId, $versionId, $fileName];
        }

        return $result;
    }

    /**
     * The pinned version when it is among the compatible ones, else the newest.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @return array<string, mixed>|null
     */
    private static function choose(array $versions, ?string $versionId): ?array
    {
        $versions = array_values(array_filter($versions, 'is_array'));

        if ($versionId !== null) {
            foreach ($versions as $candidate) {
                if (($candidate['id'] ?? null) === $versionId) {
                    return $candidate;
                }
            }
        }

        return $versions[0] ?? null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{project_id: ?string, version_id: ?string, file_name: ?string, reason: string, required_by: string} */
    private static function unresolved(?string $projectId, ?string $versionId, ?string $fileName, string $reason, string $requiredBy): array
    {
        return ['project_id' => $projectId, 'version_id' => $versionId, 'file_name' => $fileName, 'reason' => $reason, 'required_by' => $requiredBy];
    }
}
