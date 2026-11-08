<?php

namespace DrMxrcy\ModrinthPlus\Filament\Server\Pages;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;

class MinecraftModrinthPluginPage extends MinecraftModrinthProjectPage
{
    protected static ?ModrinthProjectType $modrinthProjectType = ModrinthProjectType::Plugin;

    protected static ?string $slug = 'modrinth_plugins';
}
