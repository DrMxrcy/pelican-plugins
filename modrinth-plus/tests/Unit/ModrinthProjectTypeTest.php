<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use PHPUnit\Framework\TestCase;

class ModrinthProjectTypeTest extends TestCase
{
    public function test_folder_matches_project_type(): void
    {
        $this->assertSame('mods', ModrinthProjectType::Mod->getFolder());
        $this->assertSame('plugins', ModrinthProjectType::Plugin->getFolder());
    }

    public function test_mod_and_plugin_folders_ignore_the_level_name(): void
    {
        $this->assertSame('mods', ModrinthProjectType::Mod->getFolder('survival'));
        $this->assertSame('plugins', ModrinthProjectType::Plugin->getFolder('survival'));
    }

    public function test_datapack_folder_is_inside_the_world(): void
    {
        $this->assertSame('world/datapacks', ModrinthProjectType::Datapack->getFolder());
        $this->assertSame('survival/datapacks', ModrinthProjectType::Datapack->getFolder('survival'));
    }
}
