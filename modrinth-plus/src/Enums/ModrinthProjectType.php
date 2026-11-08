<?php

namespace DrMxrcy\ModrinthPlus\Enums;

use App\Models\Server;
use DrMxrcy\ModrinthPlus\Support\ServerProperties;
use Filament\Support\Contracts\HasLabel;

enum ModrinthProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';
    case Datapack = 'datapack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => trans('modrinth-plus::strings.minecraft_mods'),
            self::Plugin => trans('modrinth-plus::strings.minecraft_plugins'),
            self::Datapack => trans('modrinth-plus::strings.minecraft_datapacks'),
        };
    }

    /**
     * Folder (relative to the server root) the project files go into. Datapacks live inside the
     * world, so they need the world folder (`level-name` from server.properties, see
     * MinecraftModrinthService::getProjectFolder()); mods and plugins ignore it.
     */
    public function getFolder(string $levelName = ServerProperties::DEFAULT_LEVEL_NAME): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::Plugin => 'plugins',
            self::Datapack => $levelName.'/datapacks',
        };
    }

    /** @return ModrinthProjectType[] */
    public static function fromServer(Server $server): array
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        $projectTypes = [];

        if (in_array('modrinth_plugins', $features) || (in_array('minecraft', $tags) && in_array('plugins', $features))) {
            $projectTypes[] = self::Plugin;
        }

        if (in_array('modrinth_mods', $features) || (in_array('minecraft', $tags) && in_array('mods', $features))) {
            $projectTypes[] = self::Mod;
        }

        if (in_array('modrinth_datapacks', $features)) {
            $projectTypes[] = self::Datapack;
        }

        return $projectTypes;
    }
}
