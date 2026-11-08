<?php

namespace DrMxrcy\ModrinthPlus;

use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Panel;

class MinecraftModrinthPlugin implements Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'modrinth-plus';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "DrMxrcy\\ModrinthPlus\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
