<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Console\Commands\PruneSamplesCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Plan 26 step 2: the daily prune keeps raw samples and hourly rollups for their own retention. */
class PruneTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PruneSamplesCommand::class));
        $this->travelTo(Carbon::parse('2026-11-09 04:20:00', 'UTC'));
    }

    private function rollup(int $serverId, Carbon $hour): HourlyRollup
    {
        $hour = $hour->copy()->startOfHour();

        return HourlyRollup::query()->create([
            'server_id' => $serverId,
            'hour_start' => $hour,
            'epoch_hour' => intdiv($hour->getTimestamp(), 3600),
            'avg_online' => 1.0,
            'max_online' => 1,
            'samples' => 30,
            'online_samples' => 30,
        ]);
    }

    private function sample(int $serverId, Carbon $at): Sample
    {
        return Sample::query()->create(['server_id' => $serverId, 'polled_at' => $at, 'status' => SampleStatus::Offline]);
    }

    public function test_prune_removes_only_rows_older_than_each_retention_setting(): void
    {
        $server = $this->createServerModel();
        config()->set('players-plus.retention_days', 7);
        config()->set('players-plus.rollup_retention_days', 30);

        $this->sample($server->id, now()->subDays(7)->subMinute());
        $keptSample = $this->sample($server->id, now()->subDays(7)->addMinute());
        // Rollups are kept far longer than raw samples.
        $this->rollup($server->id, now()->subDays(31));
        $keptRollups = [
            $this->rollup($server->id, now()->subDays(29))->id,
            $this->rollup($server->id, now()->subDays(8))->id,
        ];

        $this->artisan('players-plus:prune')->assertSuccessful();

        $this->assertSame([$keptSample->id], Sample::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing($keptRollups, HourlyRollup::query()->pluck('id')->all());
    }

    public function test_rollup_retention_defaults_to_a_year(): void
    {
        $server = $this->createServerModel();

        $this->rollup($server->id, now()->subDays(366));
        $kept = $this->rollup($server->id, now()->subDays(364));

        $this->artisan('players-plus:prune')->assertSuccessful();

        $this->assertSame([$kept->id], HourlyRollup::query()->pluck('id')->all());
    }
}
