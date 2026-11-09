<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\NotEnoughDiskException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\DiskBudget;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\TestCase;

/** Plan 38: PlanValidator's whole-pack limit is min(configured cap, disk budget). */
class PlanValidatorDiskBudgetTest extends TestCase
{
    private const GIB = 1024 * 1024 * 1024;

    private function plan(int $files): InstallPlan
    {
        $list = [];
        for ($i = 0; $i < $files; $i++) {
            $list[] = PlanFile::fromArray([
                'path' => "mods/m$i.jar",
                'urls' => ["https://cdn.modrinth.com/data/AANobbMI/versions/v$i/m$i.jar"],
                'size' => 256 * 1024 * 1024,
                'hashes' => ['sha1' => str_repeat('a', 40)],
                'side' => 'required',
            ]);
        }

        return new InstallPlan('modrinth', 'p', 'v', 'P', '1', '1.20.1', 'fabric', null, files: $list);
    }

    public function test_budget_refuses_what_the_disk_cannot_hold(): void
    {
        $budget = DiskBudget::forServer(10 * 1024, 3 * self::GIB, new Caps());
        $validator = new PlanValidator(new Caps(), ['world'], PlanValidator::ALLOWED_HOSTS, $budget);

        $this->assertCount(20, $validator->validate($this->plan(20))->files); // 5 GB

        try {
            $validator->validate($this->plan(28)); // 7 GB
            $this->fail('expected not_enough_disk');
        } catch (NotEnoughDiskException $exception) {
            $this->assertSame('not_enough_disk', $exception->reason);
            $this->assertSame('8 GB / 7 GB / 10 GB', $exception->detail);
            $this->assertSame(7 * self::GIB, $exception->packBytes);
            $this->assertInstanceOf(UnsafePlanException::class, $exception);
        }
    }

    public function test_configured_cap_still_wins_on_a_roomy_disk(): void
    {
        $budget = DiskBudget::forServer(1024 * 1024, 0, new Caps(maxTotalBytes: 4 * self::GIB));
        $validator = new PlanValidator(new Caps(maxTotalBytes: 4 * self::GIB), ['world'], PlanValidator::ALLOWED_HOSTS, $budget);

        $this->expectExceptionObject(new UnsafePlanException('total_too_large', (string) (5 * self::GIB)));
        $validator->validate($this->plan(20));
    }

    public function test_without_a_budget_only_the_cap_applies(): void
    {
        $this->assertCount(28, (new PlanValidator(new Caps()))->validate($this->plan(28))->files);
    }
}
