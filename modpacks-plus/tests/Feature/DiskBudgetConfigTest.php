<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Tests\TestCase;

/** Plan 38: the shipped config raises the total cap to 20 GB and adds the disk headroom settings. */
class DiskBudgetConfigTest extends TestCase
{
    public function test_shipped_config_defaults(): void
    {
        $caps = Caps::fromConfig();

        $this->assertSame(20 * 1024 * 1024 * 1024, $caps->maxTotalBytes);
        $this->assertSame(10, $caps->diskHeadroomPercent);
        $this->assertSame(512 * 1024 * 1024, $caps->diskHeadroomMinBytes);
        $this->assertSame(256 * 1024 * 1024, $caps->maxFileBytes);
        $this->assertSame(32 * 1024 * 1024, $caps->maxPanelFetchBytes);
        $this->assertSame(16 * 1024 * 1024, $caps->maxOverrideBytes);
        $this->assertSame(100 * 1024 * 1024, $caps->maxArchiveBytes);
    }

    public function test_headroom_may_be_zero_and_percent_is_bounded(): void
    {
        config()->set('modpacks-plus.caps.disk_headroom_percent', 0);
        config()->set('modpacks-plus.caps.disk_headroom_min_mb', 0);
        $caps = Caps::fromConfig();
        $this->assertSame(0, $caps->diskHeadroomPercent);
        $this->assertSame(0, $caps->diskHeadroomMinBytes);

        config()->set('modpacks-plus.caps.disk_headroom_percent', 250);
        config()->set('modpacks-plus.caps.disk_headroom_min_mb', -4);
        $caps = Caps::fromConfig();
        $this->assertSame(90, $caps->diskHeadroomPercent);
        $this->assertSame(512 * 1024 * 1024, $caps->diskHeadroomMinBytes);
    }
}
