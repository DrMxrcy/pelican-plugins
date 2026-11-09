<?php

namespace DrMxrcy\PlayersPlus\Support;

use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Enums\ActivityRange;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Player-count history of one server: chart series, peaks, 7-day average and the weekday x hour
 * heatmap. Every aggregate runs in SQL on the (server_id, ...) indexes; PHP only places the
 * already-aggregated values into slots. Storage is UTC; "today", "this week" and the heatmap
 * cells follow $timezone (the panel user's).
 *
 * Offline and unreachable samples are never counted as 0 players: they don't enter any average
 * or peak, and the chart shows them (and hours without samples) as gaps (null).
 *
 * The *Query() methods are plain query builders so other views (the cross-server overview) can
 * reuse or extend them; the other methods run them, one query each.
 */
final class ActivityStats
{
    /** Weeks of rollups behind the heatmap: four of each weekday. */
    public const HEATMAP_DAYS = 28;

    public const AVERAGE_DAYS = 7;

    private const HOURS_PER_WEEK = 168;

    public readonly CarbonImmutable $now;

    public function __construct(
        public readonly int $serverId,
        public readonly string $timezone = 'UTC',
        ?CarbonImmutable $now = null,
    ) {
        $this->now = ($now ?? CarbonImmutable::now())->utc();
    }

    // ---- query builders ---------------------------------------------------------------------

    /** Raw samples (polled_at, status, online) since $from, oldest first. */
    public function rawSeriesQuery(CarbonImmutable $from): Builder
    {
        return Sample::query()->toBase()
            ->where('server_id', $this->serverId)
            ->where('polled_at', '>=', $from->utc())
            ->orderBy('polled_at')
            ->select(['polled_at', 'status', 'online']);
    }

    /** Hours with at least one online sample since $from: epoch_hour and avg_online. */
    public function hourlySeriesQuery(CarbonImmutable $from): Builder
    {
        return HourlyRollup::query()->toBase()
            ->where('server_id', $this->serverId)
            ->where('hour_start', '>=', $from->utc())
            ->where('online_samples', '>', 0)
            ->orderBy('hour_start')
            ->select(['epoch_hour', 'avg_online']);
    }

    /**
     * Peaks from raw samples not yet (fully) rolled up: today, this week, and the whole window.
     * The window reaches back to the previous hour, which the hourly rollup may still be behind on.
     */
    public function recentPeaksQuery(): Builder
    {
        $today = $this->startOfToday();
        $week = $this->startOfWeek();
        $from = $today->min($this->now->startOfHour()->subHour());
        $online = SampleStatus::Online->value;

        return Sample::query()->toBase()
            ->where('server_id', $this->serverId)
            ->where('status', $online)
            ->whereNotNull('online')
            ->where('polled_at', '>=', $from)
            ->selectRaw('MAX(CASE WHEN polled_at >= ? THEN online END) AS today', [$today])
            ->selectRaw('MAX(CASE WHEN polled_at >= ? THEN online END) AS week', [$week])
            ->selectRaw('MAX(online) AS recent');
    }

    /** Week and all-time peaks plus the sums behind the 7-day average, from the rollups. */
    public function rollupSummaryQuery(): Builder
    {
        $week = $this->startOfWeek();
        $average = $this->averageFrom();

        return HourlyRollup::query()->toBase()
            ->where('server_id', $this->serverId)
            ->selectRaw('MAX(CASE WHEN hour_start >= ? THEN max_online END) AS week', [$week])
            ->selectRaw('MAX(max_online) AS all_time')
            // Sample-weighted: sum(avg * n) / sum(n) is the average over every online sample.
            ->selectRaw('SUM(CASE WHEN hour_start >= ? THEN avg_online * online_samples END) AS average_total', [$average])
            ->selectRaw('SUM(CASE WHEN hour_start >= ? THEN online_samples END) AS average_samples', [$average]);
    }

    /**
     * Online-sample totals per local hour of the week (0 = Thursday 00:00 local, the weekday of
     * the Unix epoch). Uses integer arithmetic on epoch_hour, so it runs the same on every driver.
     */
    public function heatmapQuery(): Builder
    {
        $cell = sprintf('(epoch_hour + %d) %% %d', $this->offsetHours(), self::HOURS_PER_WEEK);

        return HourlyRollup::query()->toBase()
            ->where('server_id', $this->serverId)
            ->where('hour_start', '>=', $this->now->startOfHour()->subDays(self::HEATMAP_DAYS))
            ->where('online_samples', '>', 0)
            ->selectRaw("$cell AS cell")
            ->selectRaw('SUM(avg_online * online_samples) AS total')
            ->selectRaw('SUM(online_samples) AS samples')
            ->groupByRaw($cell);
    }

    // ---- results ----------------------------------------------------------------------------

    /**
     * Chart points for a range, oldest first. 24 h has one slot per poll interval from raw
     * samples; 7 d and 30 d have one slot per hour (average online) from the rollups. Slots that
     * were offline, unreachable or not polled are null, so the chart draws a gap.
     *
     * @return array{labels: list<string>, data: list<int|float|null>}
     */
    public function series(ActivityRange $range): array
    {
        return $range->readsRawSamples() ? $this->rawSeries($range) : $this->hourlySeries($range);
    }

    /** @return array{labels: list<string>, data: list<int|float|null>} */
    private function rawSeries(ActivityRange $range): array
    {
        $length = Settings::pollInterval() * 60;
        $count = intdiv($range->hours() * 3600, $length);
        $last = intdiv($this->now->getTimestamp(), $length) * $length;
        $first = $last - ($count - 1) * $length;

        $data = array_fill(0, $count, null);
        foreach ($this->rawSeriesQuery(CarbonImmutable::createFromTimestampUTC($first))->get() as $row) {
            $slot = intdiv(Carbon::parse($row->polled_at, 'UTC')->getTimestamp() - $first, $length);
            if ($slot < 0 || $slot >= $count || $row->status !== SampleStatus::Online->value || $row->online === null) {
                continue;
            }
            $data[$slot] = max($data[$slot] ?? 0, (int) $row->online);
        }

        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $this->label($first + $i * $length, $range);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** @return array{labels: list<string>, data: list<int|float|null>} */
    private function hourlySeries(ActivityRange $range): array
    {
        $count = $range->hours();
        $firstHour = intdiv($this->now->getTimestamp(), 3600) - ($count - 1);

        $data = array_fill(0, $count, null);
        foreach ($this->hourlySeriesQuery(CarbonImmutable::createFromTimestampUTC($firstHour * 3600))->get() as $row) {
            $slot = (int) $row->epoch_hour - $firstHour;
            if ($slot >= 0 && $slot < $count && $row->avg_online !== null) {
                $data[$slot] = round((float) $row->avg_online, 2);
            }
        }

        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $this->label(($firstHour + $i) * 3600, $range);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Peak online today and this week (local time), all-time peak (as far back as rollups are
     * kept) and the 7-day average over online samples. Null when there is no online sample.
     *
     * @return array{today: int|null, week: int|null, all_time: int|null, average_7d: float|null}
     */
    public function peaks(): array
    {
        $recent = $this->recentPeaksQuery()->first();
        $rollups = $this->rollupSummaryQuery()->first();

        $samples = (int) ($rollups->average_samples ?? 0);

        return [
            'today' => self::intOrNull($recent->today ?? null),
            'week' => self::maxOf($recent->week ?? null, $rollups->week ?? null),
            'all_time' => self::maxOf($recent->recent ?? null, $rollups->all_time ?? null),
            'average_7d' => $samples > 0 ? round((float) $rollups->average_total / $samples, 1) : null,
        ];
    }

    /**
     * Average online players per local weekday and hour over the last HEATMAP_DAYS days, counting
     * online samples only. Keys are ISO weekdays 1 (Monday) to 7 (Sunday), each with 24 hours;
     * a cell with no online sample is null (empty), never 0.
     *
     * @return array<int, list<float|null>>
     */
    public function heatmap(): array
    {
        $grid = [];
        for ($day = 1; $day <= 7; $day++) {
            $grid[$day] = array_fill(0, 24, null);
        }

        foreach ($this->heatmapQuery()->get() as $row) {
            $samples = (int) $row->samples;
            if ($samples <= 0) {
                continue;
            }
            $cell = (int) $row->cell;
            // Cell 0 is a Thursday (ISO 4).
            $day = (intdiv($cell, 24) + 3) % 7 + 1;
            $grid[$day][$cell % 24] = round((float) $row->total / $samples, 1);
        }

        return $grid;
    }

    // ---- time helpers -----------------------------------------------------------------------

    public function startOfToday(): CarbonImmutable
    {
        return $this->now->setTimezone($this->timezone)->startOfDay()->utc();
    }

    /** Weeks start on Monday (ISO), like the heatmap rows. */
    public function startOfWeek(): CarbonImmutable
    {
        return $this->now->setTimezone($this->timezone)->startOfWeek(Carbon::MONDAY)->utc();
    }

    public function averageFrom(): CarbonImmutable
    {
        return $this->now->startOfHour()->subHours(self::AVERAGE_DAYS * 24 - 1);
    }

    /**
     * Whole hours the user's timezone is ahead of UTC right now, folded into 0..167. Half-hour
     * zones round down, and a DST change inside the heatmap window shifts those days by an hour.
     */
    public function offsetHours(): int
    {
        $hours = (int) floor($this->now->setTimezone($this->timezone)->getOffset() / 3600);

        return (($hours % self::HOURS_PER_WEEK) + self::HOURS_PER_WEEK) % self::HOURS_PER_WEEK;
    }

    private function label(int $timestamp, ActivityRange $range): string
    {
        $time = CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($this->timezone)->locale(app()->getLocale());

        return $time->isoFormat(match ($range) {
            ActivityRange::Day => 'HH:mm',
            ActivityRange::Week => 'ddd HH:mm',
            ActivityRange::Month => 'D MMM HH:mm',
        });
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function maxOf(mixed ...$values): ?int
    {
        $values = array_filter(array_map(self::intOrNull(...), $values), fn (?int $value) => $value !== null);

        return $values === [] ? null : max($values);
    }
}
