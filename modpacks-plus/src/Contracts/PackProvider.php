<?php

namespace DrMxrcy\ModpacksPlus\Contracts;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * A modpack source (Modrinth now; CurseForge, FTB and ATLauncher later). Providers only
 * read: they never touch a server. resolvePlan() turns one pack version into a validated
 * InstallPlan the install engine runs.
 *
 * @phpstan-type PackHit array{id: string, slug: string, title: string, description: string, author: string, icon_url: ?string, downloads: int, follows: int, date_modified: ?string, categories: array<int, string>, game_versions: array<int, string>}
 * @phpstan-type PackVersion array{id: string, project_id: string, name: string, version_number: string, version_type: string, game_versions: array<int, string>, loaders: array<int, string>, date_published: ?string, downloads: int, changelog: ?string, file: array{url: string, filename: string, size: int, hashes: array<string, string>}|null}
 */
interface PackProvider
{
    public function id(): string;

    /**
     * @param  array<int, string>  $categories
     * @return array{hits: array<int, PackHit>, total: int}
     *
     * @throws PackUnavailableException
     */
    public function search(?string $query, ?string $minecraftVersion, ?string $loader, array $categories = [], ?string $sort = null, int $page = 1, int $perPage = 20): array;

    /** @return array<int, string> */
    public function categories(): array;

    /**
     * Pack details for the detail view: title, description (markdown body), gallery, links.
     *
     * @return array<string, mixed>
     *
     * @throws PackUnavailableException
     */
    public function project(string $packId): array;

    /**
     * Every version of a pack, newest first.
     *
     * @return array<int, PackVersion>
     *
     * @throws PackUnavailableException
     */
    public function versions(string $packId): array;

    /**
     * Downloads what is needed to read the pack version (into $workDir, on the panel) and
     * returns its plan, already checked by $validator.
     *
     * @throws UnsafePlanException a safety rule was broken
     * @throws PackUnavailableException the source could not be read
     */
    public function resolvePlan(string $packId, string $versionId, PlanValidator $validator, string $workDir): InstallPlan;
}
