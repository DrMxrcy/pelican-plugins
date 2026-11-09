<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Enums\ActivityRange;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Plan 26 step 3: series, peaks, 7-day average and heatmap against hand-computed values, with
 * online, offline and unreachable spans seeded. Offline time must never count as 0 players.
 */
class ActivityStatsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Wednesday 2026-11-11 14:37 UTC; the week started Monday 2026-11-09. */
    private const NOW = '2026-11-11 14:37:00';

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(RollupCommand::class));
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        app()->setLocale('en');
        $this->server = $this->createServerModel();
    }

    private function sample(string $at, SampleStatus $status, ?int $online = null): void
    {
        Sample::query()->create([
            'server_id' => $this->server->id,
            'polled_at' => CarbonImmutable::parse($at, 'UTC'),
            'status' => $status,
            'online' => $online,
            'max' => $status === SampleStatus::Online ? 20 : null,
        ]);
    }

    /**
     * Wed 12:00-12:08: 3, 5, offline, unreachable, 0 (a real zero). Wed 11:00 offline only.
     * Tue 20:00: 9. Mon 10:00: 11. Older than 7 days: a Sunday 2026-11-01 10:00 rollup, avg 4, peak 15.
     */
    private function seedHistory(): void
    {
        $this->sample('2026-11-09 10:00:00', SampleStatus::Online, 11);
        $this->sample('2026-11-10 20:00:00', SampleStatus::Online, 9);
        $this->sample('2026-11-11 11:00:00', SampleStatus::Offline);
        $this->sample('2026-11-11 11:30:00', SampleStatus::Unreachable);
        $this->sample('2026-11-11 12:00:00', SampleStatus::Online, 3);
        $this->sample('2026-11-11 12:02:00', SampleStatus::Online, 5);
        $this->sample('2026-11-11 12:04:00', SampleStatus::Offline);
        $this->sample('2026-11-11 12:06:00', SampleStatus::Unreachable);
        $this->sample('2026-11-11 12:08:00', SampleStatus::Online, 0);

        $this->artisan('players-plus:rollup', ['--hours' => 24 * 3])->assertSuccessful();

        $hour = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');
        HourlyRollup::query()->create([
            'server_id' => $this->server->id,
            'hour_start' => $hour,
            'epoch_hour' => intdiv($hour->getTimestamp(), 3600),
            'avg_online' => 4.0,
            'max_online' => 15,
            'samples' => 30,
            'online_samples' => 30,
        ]);

        // Another server's data never leaks in.
        $other = $this->createServerModel();
        Sample::query()->create(['server_id' => $other->id, 'polled_at' => now()->subMinutes(5), 'status' => SampleStatus::Online, 'online' => 99]);
        RollupCommand::rollupHour(CarbonImmutable::now()->startOfHour());
    }

    private function stats(string $timezone = 'UTC'): ActivityStats
    {
        return new ActivityStats($this->server->id, $timezone);
    }

    /** @return array<string, int|float|null> */
    private function byLabel(ActivityRange $range, string $timezone = 'UTC'): array
    {
        $series = $this->stats($timezone)->series($range);
        $this->assertCount($range->hours() * ($range === ActivityRange::Day ? 30 : 1), $series['data']);
        $this->assertCount(count($series['data']), $series['labels']);

        return array_combine($series['labels'], $series['data']);
    }

    public function test_24h_series_shows_gaps_for_offline_unreachable_and_unpolled_slots(): void
    {
        $this->seedHistory();

        $points = $this->byLabel(ActivityRange::Day);

        $this->assertSame(3, $points['12:00']);
        $this->assertSame(5, $points['12:02']);
        $this->assertNull($points['12:04'], 'offline is a gap, not 0');
        $this->assertNull($points['12:06'], 'unreachable is a gap, not 0');
        $this->assertSame(0, $points['12:08'], 'a polled empty server is a real 0');
        $this->assertNull($points['12:10'], 'not polled');
        $this->assertNull($points['11:00']);
        $this->assertSame(9, $points['20:00']);
        $this->assertSame('14:36', array_key_last($points));
    }

    public function test_7d_and_30d_series_read_hourly_rollups_with_gaps(): void
    {
        $this->seedHistory();

        $week = $this->byLabel(ActivityRange::Week);
        $this->assertSame(2.67, $week['Wed 12:00'], '(3 + 5 + 0) / 3 online samples');
        $this->assertNull($week['Wed 11:00'], 'offline-only hour is a gap');
        $this->assertNull($week['Wed 13:00'], 'hour without samples is a gap');
        $this->assertSame(9.0, $week['Tue 20:00']);
        $this->assertSame(11.0, $week['Mon 10:00']);
        $this->assertSame('Wed 14:00', array_key_last($week));

        $month = $this->byLabel(ActivityRange::Month);
        $this->assertSame(4.0, $month['1 Nov 10:00']);
        $this->assertSame(2.67, $month['11 Nov 12:00']);
    }

    public function test_long_ranges_never_load_raw_samples(): void
    {
        $this->seedHistory();

        foreach ([ActivityRange::Week, ActivityRange::Month] as $range) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->stats()->series($range);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertCount(1, $queries);
            $this->assertStringContainsString('pp_hourly', $queries[0]['query']);
            $this->assertStringNotContainsString('pp_samples', $queries[0]['query']);
        }
    }

    public function test_peaks_and_average_match_hand_computed_values(): void
    {
        $this->seedHistory();

        $this->assertSame([
            // Today (Wed, UTC): 3, 5, 0.
            'today' => 5,
            // Since Monday: 11 on Monday beats 9 on Tuesday.
            'week' => 11,
            // The 15 from 1 November is only in the rollups.
            'all_time' => 15,
            // (11 + 9 + 3 + 5 + 0) / 5 online samples. Counting the 4 offline/unreachable
            // samples as 0 would give 28 / 9 = 3.1.
            'average_7d' => 5.6,
        ], $this->stats()->peaks());
    }

    public function test_peaks_include_samples_the_rollup_has_not_reached_yet(): void
    {
        $this->seedHistory();
        // Polled after the last rollup ran.
        $this->sample('2026-11-11 14:36:00', SampleStatus::Online, 42);

        $peaks = $this->stats()->peaks();

        $this->assertSame(42, $peaks['today']);
        $this->assertSame(42, $peaks['week']);
        $this->assertSame(42, $peaks['all_time']);
    }

    public function test_no_online_sample_gives_empty_peaks_not_zero(): void
    {
        $this->sample('2026-11-11 12:00:00', SampleStatus::Offline);
        $this->artisan('players-plus:rollup')->assertSuccessful();

        $this->assertSame(['today' => null, 'week' => null, 'all_time' => null, 'average_7d' => null], $this->stats()->peaks());
    }

    public function test_heatmap_is_7_by_24_with_empty_cells_and_offline_excluded(): void
    {
        $this->seedHistory();

        $grid = $this->stats()->heatmap();

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($grid));
        foreach ($grid as $hours) {
            $this->assertCount(24, $hours);
        }
        $this->assertSame(11.0, $grid[1][10]);
        $this->assertSame(9.0, $grid[2][20]);
        $this->assertSame(2.7, $grid[3][12]);
        $this->assertNull($grid[3][11], 'offline-only hour stays empty');
        $this->assertSame(4.0, $grid[7][10]);

        $filled = array_sum(array_map(fn (array $hours) => count(array_filter($hours, fn ($v) => $v !== null)), $grid));
        $this->assertSame(4, $filled);
    }

    public function test_heatmap_weights_hours_by_online_samples(): void
    {
        // Two Wednesdays at 12:00: one hour with 1 online sample of 10, one with 3 samples of 2.
        foreach ([['2026-11-04 12:00:00', 10.0, 1], ['2026-11-11 12:00:00', 2.0, 3]] as [$at, $avg, $n]) {
            $hour = CarbonImmutable::parse($at, 'UTC');
            HourlyRollup::query()->create([
                'server_id' => $this->server->id, 'hour_start' => $hour, 'epoch_hour' => intdiv($hour->getTimestamp(), 3600),
                'avg_online' => $avg, 'max_online' => (int) $avg, 'samples' => 30, 'online_samples' => $n,
            ]);
        }

        // (10 * 1 + 2 * 3) / 4 = 4.0, not the mean of hourly averages (6.0).
        $this->assertSame(4.0, $this->stats()->heatmap()[3][12]);
    }

    public function test_user_timezone_moves_today_and_heatmap_cells(): void
    {
        $this->seedHistory();
        // 03:00 UTC Wednesday is 22:00 Tuesday in New York (UTC-5 in November).
        $this->sample('2026-11-11 03:00:00', SampleStatus::Online, 7);
        RollupCommand::rollupHour(CarbonImmutable::parse('2026-11-11 03:00:00', 'UTC'));

        $this->assertSame(7, $this->stats('UTC')->peaks()['today']);
        $this->assertSame(5, $this->stats('America/New_York')->peaks()['today']);

        $grid = $this->stats('America/New_York')->heatmap();
        $this->assertSame(2.7, $grid[3][7]);
        $this->assertSame(7.0, $grid[2][22]);
        $this->assertSame(9.0, $grid[2][15]);
        $this->assertSame(4.0, $grid[7][5]);

        $points = $this->byLabel(ActivityRange::Day, 'America/New_York');
        $this->assertSame(3, $points['07:00']);
    }

    public function test_stats_render_with_four_queries_and_30_days_of_data(): void
    {
        $this->seedMonth($this->server->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = $this->stats();
        $stats->series(ActivityRange::Month);
        $stats->peaks();
        $stats->heatmap();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(4, $queries);
    }

    /** 30 days of 2-minute samples (21,600), every 7th offline, rolled up hour by hour in SQL. */
    private function seedMonth(int $serverId): void
    {
        $start = CarbonImmutable::now()->startOfHour()->subDays(30);
        $rows = [];
        for ($i = 0; $i < 30 * 24 * 30; $i++) {
            $offline = $i % 7 === 0;
            $rows[] = [
                'server_id' => $serverId,
                'polled_at' => $start->addMinutes(2 * $i)->toDateTimeString(),
                'status' => $offline ? SampleStatus::Offline->value : SampleStatus::Online->value,
                'online' => $offline ? null : $i % 5,
                'max' => 20,
            ];
            if (count($rows) === 500) {
                Sample::query()->insert($rows);
                $rows = [];
            }
        }
        Sample::query()->insert($rows);
        $this->artisan('players-plus:rollup', ['--hours' => 24 * 30 + 1])->assertSuccessful();
        $this->assertSame(21600, Sample::query()->count());
    }
}
