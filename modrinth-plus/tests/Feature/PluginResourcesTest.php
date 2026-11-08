<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Tests\TestCase;

class PluginResourcesTest extends TestCase
{
    public function test_plugin_config_is_registered(): void
    {
        $this->assertTrue(config()->has('modrinth-plus'));
        $this->assertSame(require dirname(__DIR__, 2) . '/config/modrinth-plus.php', config('modrinth-plus'));
    }

    public function test_plugin_translations_resolve(): void
    {
        $this->assertSame('Modrinth', trans('modrinth-plus::strings.plugin_name'));
        $this->assertSame('Minecraft Mods', ModrinthProjectType::Mod->getLabel());
    }
}
