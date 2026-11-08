<?php

namespace DrMxrcy\ModrinthPlus\Filament\Server\Pages;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use Filament\Facades\Filament;

/**
 * Datapacks from Modrinth, installed into the main world's `datapacks/` folder (`level-name` in
 * server.properties). Shown when the egg has the `modrinth_datapacks` feature. Datapacks always
 * run on the server, so the client-only toggle and side badges are left out.
 *
 * Based on the datapack page of YONN2222/pelican-minecraft-modrinth (GPL-3.0).
 */
class MinecraftModrinthDatapackPage extends MinecraftModrinthProjectPage
{
    protected static ?ModrinthProjectType $modrinthProjectType = ModrinthProjectType::Datapack;

    protected static ?string $slug = 'modrinth_datapacks';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-file-zip';

    protected static ?int $navigationSort = 31;

    /** Read from server.properties once per request; not Livewire state, so a changed level-name shows up on the next one. */
    protected ?string $projectFolder = null;

    protected static function usesClientOnlyFilter(): bool
    {
        return false;
    }

    /** Scan folder hashes untracked jars; datapacks are zips or folders, so no scan and no "Not on Modrinth" rows here. */
    protected static function supportsFolderScan(): bool
    {
        return false;
    }

    protected static function modalHeading(string $key): string
    {
        return trans("modrinth-plus::strings.datapacks.$key");
    }

    protected function getProjectFolder(): string
    {
        if ($this->projectFolder === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            $this->projectFolder = MinecraftModrinth::getProjectFolder($server, ModrinthProjectType::Datapack);
        }

        return $this->projectFolder;
    }

    /** @param  array<mixed>  $file */
    protected function isProjectFile(array $file): bool
    {
        return ($file['mime'] ?? null) === 'application/zip' || str(is_string($file['name'] ?? null) ? $file['name'] : '')->lower()->endsWith('.zip');
    }
}
