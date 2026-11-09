<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\DiskBudget;
use PHPUnit\Framework\TestCase;

/**
 * Plan 38: the whole-pack limit follows the server's disk. A disk-limited server may use
 * its free space minus headroom (never more than the configured cap); an unlimited server
 * or one whose usage the node couldn't report gets the configured cap.
 */
class DiskBudgetTest extends TestCase
{
    private const GIB = 1024 * 1024 * 1024;

    private const MIB = 1024 * 1024;

    public function test_defaults_raise_the_total_cap_to_20_gb_and_keep_every_per_file_cap(): void
    {
        $caps = new Caps();

        $this->assertSame(20 * self::GIB, $caps->maxTotalBytes);
        $this->assertSame(10, $caps->diskHeadroomPercent);
        $this->assertSame(512 * self::MIB, $caps->diskHeadroomMinBytes);
        // Per-file caps protect panel memory and stay as they were.
        $this->assertSame(256 * self::MIB, $caps->maxFileBytes);
        $this->assertSame(32 * self::MIB, $caps->maxPanelFetchBytes);
        $this->assertSame(16 * self::MIB, $caps->maxOverrideBytes);
        $this->assertSame(100 * self::MIB, $caps->maxArchiveBytes);
    }

    public function test_limited_server_gets_free_space_minus_headroom(): void
    {
        // 10 GB limit, 3 GB used: 7 GB free, 1 GB headroom (10 %), so 6 GB for the pack.
        $budget = DiskBudget::forServer(10 * 1024, 3 * self::GIB, new Caps());

        $this->assertTrue($budget->limited());
        $this->assertTrue($budget->usageKnown());
        $this->assertSame(7 * self::GIB, $budget->freeBytes());
        $this->assertSame(1 * self::GIB, $budget->headroomBytes);
        $this->assertSame(6 * self::GIB, $budget->allowedBytes());
        $this->assertTrue($budget->fits(5 * self::GIB));
        $this->assertTrue($budget->fits(6 * self::GIB));
        $this->assertFalse($budget->fits(6 * self::GIB + 1));
        $this->assertFalse($budget->fitsDisk(7 * self::GIB));
        $this->assertSame(8 * self::GIB, $budget->neededBytes(7 * self::GIB));
    }

    public function test_headroom_has_a_floor(): void
    {
        // 2 GB limit: 10 % is 204.8 MB, below the 512 MB floor.
        $budget = DiskBudget::forServer(2048, 0, new Caps());

        $this->assertSame(512 * self::MIB, $budget->headroomBytes);
        $this->assertSame(2 * self::GIB - 512 * self::MIB, $budget->allowedBytes());
    }

    public function test_configured_cap_still_bounds_a_huge_disk(): void
    {
        $budget = DiskBudget::forServer(1024 * 1024, 0, new Caps(maxTotalBytes: 5 * self::GIB));

        $this->assertSame(5 * self::GIB, $budget->allowedBytes());
        $this->assertFalse($budget->fits(6 * self::GIB));
        // The disk itself has room: the cap, not the disk, is what refuses it.
        $this->assertTrue($budget->fitsDisk(6 * self::GIB));
    }

    public function test_unlimited_server_gets_the_configured_cap(): void
    {
        $budget = DiskBudget::forServer(0, 900 * self::GIB, new Caps());

        $this->assertFalse($budget->limited());
        $this->assertNull($budget->freeBytes());
        $this->assertSame(0, $budget->headroomBytes);
        $this->assertSame(20 * self::GIB, $budget->allowedBytes());
        $this->assertTrue($budget->fitsDisk(PHP_INT_MAX));
        $this->assertTrue($budget->fits(20 * self::GIB));
        $this->assertFalse($budget->fits(20 * self::GIB + 1));
    }

    public function test_unknown_usage_falls_back_to_the_configured_cap_never_to_unlimited(): void
    {
        $budget = DiskBudget::forServer(10 * 1024, null, new Caps(maxTotalBytes: 4 * self::GIB));

        $this->assertTrue($budget->limited());
        $this->assertFalse($budget->usageKnown());
        $this->assertNull($budget->freeBytes());
        $this->assertSame(4 * self::GIB, $budget->allowedBytes());
        $this->assertTrue($budget->fitsDisk(PHP_INT_MAX));
        $this->assertFalse($budget->fits(4 * self::GIB + 1));
    }

    public function test_over_the_limit_leaves_nothing(): void
    {
        $budget = DiskBudget::forServer(1024, 2 * self::GIB, new Caps());

        $this->assertSame(0, $budget->freeBytes());
        $this->assertSame(0, $budget->allowedBytes());
        $this->assertFalse($budget->fits(1));
        $this->assertTrue($budget->fits(0));
    }

    public function test_negative_inputs_are_clamped(): void
    {
        $budget = DiskBudget::forServer(-5, -10, new Caps());

        $this->assertFalse($budget->limited());
        $this->assertSame(0, $budget->usedBytes);
    }

    public function test_headroom_percent_and_floor_follow_caps(): void
    {
        $caps = new Caps(diskHeadroomPercent: 0, diskHeadroomMinBytes: 0);

        $this->assertSame(0, DiskBudget::forServer(10 * 1024, 0, $caps)->headroomBytes);
        $this->assertSame(2 * self::GIB, DiskBudget::forServer(10 * 1024, 0, new Caps(diskHeadroomPercent: 20))->headroomBytes);
    }

    public function test_human_sizes(): void
    {
        $this->assertSame('0 B', DiskBudget::human(0));
        $this->assertSame('512 B', DiskBudget::human(512));
        $this->assertSame('1.5 KB', DiskBudget::human(1536));
        $this->assertSame('512 MB', DiskBudget::human(512 * self::MIB));
        $this->assertSame('10 GB', DiskBudget::human(10 * self::GIB));
        $this->assertSame('6.2 GB', DiskBudget::human((int) (6.2 * self::GIB)));
        $this->assertSame('1.5 TB', DiskBudget::human(1536 * self::GIB));
        $this->assertSame('0 B', DiskBudget::human(-3));
    }

    public function test_detail_round_trips_needed_free_and_limit(): void
    {
        $budget = DiskBudget::forServer(10 * 1024, 3 * self::GIB, new Caps());
        $detail = $budget->detail(7 * self::GIB);

        $this->assertSame('8 GB / 7 GB / 10 GB', $detail);
        $this->assertSame(['needed' => '8 GB', 'free' => '7 GB', 'limit' => '10 GB'], DiskBudget::detailParams($detail));
    }

    public function test_detail_params_never_fail_on_odd_input(): void
    {
        $this->assertSame(['needed' => '?', 'free' => '?', 'limit' => '?'], DiskBudget::detailParams(''));
        $this->assertSame(['needed' => '?', 'free' => '?', 'limit' => '?'], DiskBudget::detailParams('a / b'));
    }
}
