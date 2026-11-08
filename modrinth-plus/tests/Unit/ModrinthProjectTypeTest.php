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
}
