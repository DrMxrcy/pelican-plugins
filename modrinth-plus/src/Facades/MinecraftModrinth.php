<?php

namespace DrMxrcy\ModrinthPlus\Facades;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Enums\ModrinthSearchIndex;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use Illuminate\Support\Facades\Facade;

/**
 * @phpstan-type InstalledModMetadata array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}
 * @phpstan-type ModrinthVersion array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: array<string>, version_type: string, loaders: array<string>, featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}
 *
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static ?string getLatestMinecraftVersion()
 * @method static array{icon: string, name: string, supported_project_types: string[], display_name: string}|null getLoaderFromServer(Server $server, ?ModrinthProjectType $modrinthProjectType = null)
 * @method static array<int, array{icon: string, name: string, supported_project_types: string[]}> getLoaders()
 * @method static array<int, string> getCategories(ModrinthProjectType $modrinthProjectType)
 * @method static array{hits: array<int, array<string, mixed>>, total_hits: int, versions: array<string, array<int, ModrinthVersion>|null>} getProjects(Server $server, ModrinthProjectType $modrinthProjectType, int $page = 1, ?string $search = null, bool $includeClientOnly = false, ?ModrinthSearchIndex $index = null, array<mixed> $categories = [])
 * @method static array<string, array<string, mixed>> getProjectsByIds(array<int, string> $projectIds)
 * @method static array<int, array<string, mixed>> getInstalledModsFromModrinth(array<int, InstalledModMetadata> $installedMods, int $page = 1)
 * @method static array<int, ModrinthVersion> getProjectVersions(string $projectId, Server $server, ?ModrinthProjectType $modrinthProjectType = null)
 * @method static array<string, array<int, ModrinthVersion>|null> getProjectVersionsBulk(array<int, string> $projectIds, Server $server, ?ModrinthProjectType $modrinthProjectType = null)
 * @method static string getProjectFolder(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static string getLevelName(Server $server)
 * @method static array<int, InstalledModMetadata> getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static array<int, InstalledModMetadata>|null readInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static bool saveModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId, string $projectSlug, string $projectTitle, string $versionId, string $versionNumber, string $filename, ?string $author = null, ?string $installedAsDependencyOf = null)
 * @method static bool removeModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId)
 * @method static void downloadFile(Server $server, string $url, string $folder, string $filename)
 * @method static void deleteFile(Server $server, string $folder, string $filename)
 * @method static void renameFile(Server $server, string $folder, string $from, string $to)
 * @method static bool fileExists(Server $server, string $folder, string $filename)
 * @method static array<string, mixed> listFolder(Server $server, string $folder)
 * @method static InstalledModMetadata|null getInstalledMod(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId)
 * @method static bool isUpdateAvailable(array{version_id: string, version_number: string} $installedMod, array<int, array{id: string, version_number: string}> $availableVersions)
 * @method static array<string> getInstalledMods(Server $server, ModrinthProjectType $modrinthProjectType)
 *
 * @see MinecraftModrinthService
 */
class MinecraftModrinth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MinecraftModrinthService::class;
    }
}
