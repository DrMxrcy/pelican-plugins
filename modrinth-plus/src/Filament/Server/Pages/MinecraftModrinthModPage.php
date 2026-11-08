<?php

namespace DrMxrcy\ModrinthPlus\Filament\Server\Pages;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;

class MinecraftModrinthModPage extends MinecraftModrinthProjectPage
{
    protected static ?ModrinthProjectType $modrinthProjectType = ModrinthProjectType::Mod;

    protected static ?string $slug = 'modrinth_mods';
}
