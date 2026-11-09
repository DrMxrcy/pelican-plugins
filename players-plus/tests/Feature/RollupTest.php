<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Providers\PlayersPlusServiceProvider;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Plan 26 step 1: hourly rollups of raw samples, idempotent, offline time excluded. */
class RollupTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(RollupCommand::class));
        // 14:37 UTC: the command rolls up 13:00 (complete) and 14:00 (in progress).
        $this->travelTo(Carbon::parse('2026-11-09 14:37:00', 'UTC'));
    }

    private function server(): Server
    {
        return $this->createServerModel();
    }

    private function sample(Server $server, string $at, SampleStatus $status, ?int $online = null): void
    {
        Sample::query()->create([
            'server_id' => $server->id,
            'polled_at' => Carbon::parse($at, 'UTC'),
            'status' => $status,
            'online' => $online,
            'max' => $status === SampleStatus::Online ? 20 : null,
        ]);
    }

    private function row(Server $server, string $hour): ?HourlyRollup
    {
        return HourlyRollup::query()->where('server_id', $server->id)->where('hour_start', Carbon::parse($hour, 'UTC'))->first();
    }

    /** @return array<string, mixed> */
    private function values(?HourlyRollup $row): array
    {
        $this->assertNotNull($row);

        return [
            'avg_online' => $row->avg_online,
            'max_online' => $row->max_online,
            'samples' => $row->samples,
            'online_samples' => $row->online_samples,
        ];
    }

    public function test_rollup_averages_online_samples_only(): void
    {
        $server = $this->server();
        $this->sample($server, '2026-11-09 13:00:00', SampleStatus::Online, 2);
        $this->sample($server, '2026-11-09 13:10:00', SampleStatus::Offline);
        $this->sample($server, '2026-11-09 13:20:00', SampleStatus::Online, 4);
        $this->sample($server, '2026-11-09 13:30:00', SampleStatus::Unreachable);
        $this->sample($server, '2026-11-09 13:58:00', SampleStatus::Online, 6);
        // The next hour starts exactly at 14:00 and must not leak into 13:00.
        $this->sample($server, '2026-11-09 14:00:00', SampleStatus::Online, 10);

        $this->artisan('players-plus:rollup')->assertSuccessful();

        // (2 + 4 + 6) / 3, not (2 + 0 + 4 + 0 + 6) / 5: offline time is not "0 players".
        $this->assertEquals(['avg_online' => 4.0, 'max_online' => 6, 'samples' => 5, 'online_samples' => 3], $this->values($this->row($server, '2026-11-09 13:00:00')));
        $this->assertEquals(['avg_online' => 10.0, 'max_online' => 10, 'samples' => 1, 'online_samples' => 1], $this->values($this->row($server, '2026-11-09 14:00:00')));
        $this->assertSame((int) intdiv(Carbon::parse('2026-11-09 13:00:00', 'UTC')->getTimestamp(), 3600), $this->row($server, '2026-11-09 13:00:00')->epoch_hour);
    }

    public function test_hour_with_no_online_sample_has_no_average_or_peak(): void
    {
        $server = $this->server();
        $this->sample($server, '2026-11-09 13:00:00', SampleStatus::Offline);
        $this->sample($server, '2026-11-09 13:30:00', SampleStatus::Unreachable);

        $this->artisan('players-plus:rollup')->assertSuccessful();

        $this->assertEquals(['avg_online' => null, 'max_online' => null, 'samples' => 2, 'online_samples' => 0], $this->values($this->row($server, '2026-11-09 13:00:00')));
        // No sample at all in 14:00: no row, so the chart shows a gap rather than a zero.
        $this->assertNull($this->row($server, '2026-11-09 14:00:00'));
    }

    public function test_rollup_is_idempotent_and_refreshes_the_current_hour(): void
    {
        $server = $this->server();
        $this->sample($server, '2026-11-09 13:05:00', SampleStatus::Online, 3);
        $this->sample($server, '2026-11-09 14:05:00', SampleStatus::Online, 1);

        $this->artisan('players-plus:rollup')->assertSuccessful();
        $first = HourlyRollup::query()->orderBy('hour_start')->get()->map(fn (HourlyRollup $row) => $row->only(['id', 'hour_start', 'avg_online', 'max_online', 'samples', 'online_samples']))->all();

        $this->artisan('players-plus:rollup')->assertSuccessful();
        $second = HourlyRollup::query()->orderBy('hour_start')->get()->map(fn (HourlyRollup $row) => $row->only(['id', 'hour_start', 'avg_online', 'max_online', 'samples', 'online_samples']))->all();

        $this->assertCount(2, $first);
        $this->assertEquals($first, $second);

        // A later poll in the current hour updates that hour's row in place.
        $this->sample($server, '2026-11-09 14:35:00', SampleStatus::Online, 5);
        $this->artisan('players-plus:rollup')->assertSuccessful();

        $this->assertSame(2, HourlyRollup::query()->count());
        $this->assertEquals(['avg_online' => 3.0, 'max_online' => 5, 'samples' => 2, 'online_samples' => 2], $this->values($this->row($server, '2026-11-09 14:00:00')));
    }

    public function test_rollup_keeps_servers_apart_and_hours_option_backfills(): void
    {
        $a = $this->server();
        $b = $this->server();
        $this->sample($a, '2026-11-09 10:15:00', SampleStatus::Online, 7);
        $this->sample($a, '2026-11-09 13:15:00', SampleStatus::Online, 1);
        $this->sample($b, '2026-11-09 13:15:00', SampleStatus::Online, 9);

        $this->artisan('players-plus:rollup')->assertSuccessful();
        $this->assertNull($this->row($a, '2026-11-09 10:00:00'), 'the default run only covers the previous and current hour');
        $this->assertSame(1, $this->row($a, '2026-11-09 13:00:00')->max_online);
        $this->assertSame(9, $this->row($b, '2026-11-09 13:00:00')->max_online);

        $this->artisan('players-plus:rollup', ['--hours' => 6])->assertSuccessful();
        $this->assertSame(7, $this->row($a, '2026-11-09 10:00:00')->max_online);
        $this->assertNull($this->row($b, '2026-11-09 10:00:00'));
    }

    public function test_rollup_is_scheduled_hourly_without_overlapping(): void
    {
        (new PlayersPlusServiceProvider($this->app))->boot();

        $rollup = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'players-plus:rollup'));

        $this->assertNotNull($rollup);
        $this->assertSame('5 * * * *', $rollup->expression);
        $this->assertTrue($rollup->withoutOverlapping);
    }
}
