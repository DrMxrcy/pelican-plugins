<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Support\VersionMatch;
use PHPUnit\Framework\TestCase;

class VersionMatchTest extends TestCase
{
    public function test_matching_version_needs_nothing(): void
    {
        $this->assertNull(VersionMatch::needs(['game_versions' => ['1.20.1'], 'loaders' => ['fabric', 'quilt']], '1.20.1', 'fabric'));
    }

    public function test_other_loader_or_version_says_what_it_needs(): void
    {
        $this->assertSame('Forge 1.20.1', VersionMatch::needs(['game_versions' => ['1.20.1'], 'loaders' => ['forge']], '1.20.1', 'fabric'));
        $this->assertSame('Fabric 1.21.1', VersionMatch::needs(['game_versions' => ['1.21.1'], 'loaders' => ['fabric']], '1.20.1', 'fabric'));
        $this->assertSame('NeoForge 1.21.1', VersionMatch::needs(['game_versions' => ['1.21.1'], 'loaders' => ['neoforge']], '1.20.1', 'fabric'));
    }

    public function test_unknown_server_never_matches(): void
    {
        $this->assertSame('Fabric 1.20.1', VersionMatch::needs(['game_versions' => ['1.20.1'], 'loaders' => ['fabric']], null, 'fabric'));
        $this->assertSame('Fabric 1.20.1', VersionMatch::needs(['game_versions' => ['1.20.1'], 'loaders' => ['fabric']], '1.20.1', null));
    }
}
