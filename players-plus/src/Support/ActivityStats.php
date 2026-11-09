<?php

namespace DrMxrcy\PlayersPlus\Support;

use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Enums\ActivityRange;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
 *
 * The cross-server methods (the "grouped" section, built with acrossServers()) take the server
 * set as an argument, a list of ids or an id subquery, and ignore $serverId. Each metric is one
 * query grouped by server_id, so the query count never grows with the number of servers.
 *
 * @phpstan-type OverviewRow array{status: SampleStatus|null, online: int|null, max: int|null, polled_at: CarbonImmutable|null, live: bool, peak_24h: int|null, average_7d: float|null, unique_7d: int}
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

    /** For the grouped cross-server methods only; the per-server methods would match nothing. */
    public static function acrossServers(string $timezone = 'UTC', ?CarbonImmutable $now = null): self
    {
        return new self(0, $timezone, $now);
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

        return $this->selectAverage(HourlyRollup::query()->toBase()
            ->where('server_id', $this->serverId)
            ->selectRaw('MAX(CASE WHEN hour_start >= ? THEN max_online END) AS week', [$week])
            ->selectRaw('MAX(max_online) AS all_time'), $average);
    }

    /** average_total and average_samples since $from, the sums behind the 7-day average. */
    private function selectAverage(Builder $query, CarbonImmutable $from): Builder
    {
        return $query
            // Sample-weighted: sum(avg * n) / sum(n) is the average over every online sample.
            ->selectRaw('SUM(CASE WHEN hour_start >= ? THEN avg_online * online_samples END) AS average_total', [$from])
            ->selectRaw('SUM(CASE WHEN hour_start >= ? THEN online_samples END) AS average_samples', [$from]);
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

    // ---- grouped cross-server query builders ------------------------------------------------

    /**
     * The newest sample of each server in $servers: server_id, polled_at, status, online, max.
     * A server without samples has no row.
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function latestSamplesQuery(BuilderContract|array $servers): Builder
    {
        $latest = Sample::query()->toBase()
            ->whereIn('server_id', $servers)
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('MAX(polled_at) AS latest_at');

        return Sample::query()->toBase()
            ->joinSub($latest, 'pp_latest', fn (JoinClause $join) => $join
                ->on('pp_samples.server_id', '=', 'pp_latest.server_id')
                ->on('pp_samples.polled_at', '=', 'pp_latest.latest_at'))
            ->select(['pp_samples.server_id', 'pp_samples.polled_at', 'pp_samples.status', 'pp_samples.online', 'pp_samples.max']);
    }

    /**
     * Peak online per server over the last 24 hours (rolling), from raw online samples.
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function peak24hQuery(BuilderContract|array $servers): Builder
    {
        return Sample::query()->toBase()
            ->whereIn('server_id', $servers)
            ->where('status', SampleStatus::Online->value)
            ->whereNotNull('online')
            ->where('polled_at', '>=', $this->now->subDay())
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('MAX(online) AS peak_24h');
    }

    /**
     * The sums behind each server's 7-day average, the same rule as rollupSummaryQuery().
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function averageQuery(BuilderContract|array $servers): Builder
    {
        $from = $this->averageFrom();

        return $this->selectAverage(HourlyRollup::query()->toBase()
            ->whereIn('server_id', $servers)
            ->where('hour_start', '>=', $from)
            ->groupBy('server_id')
            ->select('server_id'), $from);
    }

    /**
     * Players seen on each server in the last 7 days (rolling). A UUID seen on two servers is two
     * players: identities are per server.
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function uniquePlayersQuery(BuilderContract|array $servers): Builder
    {
        return Player::query()->toBase()
            ->whereIn('server_id', $servers)
            ->where('last_seen_at', '>=', $this->now->subDays(self::AVERAGE_DAYS))
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('COUNT(*) AS players');
    }

    /**
     * Players online right now across $servers: servers whose newest sample is online and fresh
     * (PollHealth), and the sum of their counts.
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function totalsQuery(BuilderContract|array $servers): Builder
    {
        return Sample::query()->toBase()->newQuery()
            ->fromSub($this->latestSamplesQuery($servers), 'pp_now')
            ->where('status', SampleStatus::Online->value)
            ->where('polled_at', '>=', PollHealth::freshFrom($this->now))
            ->selectRaw('COUNT(*) AS servers_online')
            ->selectRaw('SUM(online) AS players');
    }

    /**
     * Players online summed over $servers per poll slot since $from (online samples only).
     *
     * @param  BuilderContract|list<int>  $servers
     */
    public function combinedSeriesQuery(BuilderContract|array $servers, CarbonImmutable $from): Builder
    {
        return Sample::query()->toBase()
            ->whereIn('server_id', $servers)
            ->where('status', SampleStatus::Online->value)
            ->whereNotNull('online')
            ->where('polled_at', '>=', $from->utc())
            ->groupBy('polled_at')
            ->orderBy('polled_at')
            ->select('polled_at')
            ->selectRaw('SUM(online) AS online');
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
        [$first, $length, $count] = $this->rawSlots($range);

        $data = array_fill(0, $count, null);
        foreach ($this->rawSeriesQuery(CarbonImmutable::createFromTimestampUTC($first))->get() as $row) {
            $slot = intdiv(Carbon::parse($row->polled_at, 'UTC')->getTimestamp() - $first, $length);
            if ($slot < 0 || $slot >= $count || $row->status !== SampleStatus::Online->value || $row->online === null) {
                continue;
            }
            $data[$slot] = max($data[$slot] ?? 0, (int) $row->online);
        }

        return ['labels' => $this->rawLabels($first, $length, $count, $range), 'data' => $data];
    }

    /**
     * One slot per poll interval over the range, the last one holding now.
     *
     * @return array{int, int, int} first slot (Unix seconds), slot length (seconds), slot count
     */
    private function rawSlots(ActivityRange $range): array
    {
        $length = Settings::pollInterval() * 60;
        $count = intdiv($range->hours() * 3600, $length);
        $last = intdiv($this->now->getTimestamp(), $length) * $length;

        return [$last - ($count - 1) * $length, $length, $count];
    }

    /** @return list<string> */
    private function rawLabels(int $first, int $length, int $count, ActivityRange $range): array
    {
        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $this->label($first + $i * $length, $range);
        }

        return $labels;
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
     * @return array<int, array<int, float|null>>
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
            if ($cell < 0 || $cell >= self::HOURS_PER_WEEK) {
                continue;
            }
            // Cell 0 is a Thursday (ISO 4).
            $day = (intdiv($cell, 24) + 3) % 7 + 1;
            $grid[$day][$cell % 24] = round((float) $row->total / $samples, 1);
        }

        return $grid;
    }

    // ---- grouped cross-server results --------------------------------------------------------

    /**
     * Per-server overview rows keyed by server id, in the order given: the newest sample (live
     * when online and fresh), the rolling 24 h peak, the 7-day average while online and the
     * players seen in the last 7 days. Four queries for any number of servers; none for none.
     *
     * @param  list<int>  $serverIds
     * @return array<int, OverviewRow>
     */
    public function overview(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        $latest = $this->latestSamplesQuery($serverIds)->get()->keyBy('server_id');
        $peaks = $this->peak24hQuery($serverIds)->pluck('peak_24h', 'server_id');
        $averages = $this->averageQuery($serverIds)->get()->keyBy('server_id');
        $players = $this->uniquePlayersQuery($serverIds)->pluck('players', 'server_id');
        $freshFrom = PollHealth::freshFrom($this->now);

        $rows = [];
        foreach ($serverIds as $id) {
            $sample = $latest->get($id);
            $status = $sample === null ? null : SampleStatus::tryFrom((string) $sample->status);
            $polledAt = $sample === null ? null : CarbonImmutable::parse($sample->polled_at, 'UTC');
            $online = $status === SampleStatus::Online ? self::intOrNull($sample->online) : null;
            $average = $averages->get($id);
            $samples = (int) ($average->average_samples ?? 0);

            $rows[$id] = [
                'status' => $status,
                'online' => $online,
                'max' => $status === SampleStatus::Online ? self::intOrNull($sample->max) : null,
                'polled_at' => $polledAt,
                'live' => $online !== null && $polledAt !== null && $polledAt->gte($freshFrom),
                'peak_24h' => self::intOrNull($peaks->get($id)),
                'average_7d' => $samples > 0 ? round((float) $average->average_total / $samples, 1) : null,
                'unique_7d' => (int) ($players->get($id) ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Players online now and how many servers they are on, over live servers only (newest sample
     * online and fresh). One query.
     *
     * @param  BuilderContract|list<int>  $servers
     * @return array{players: int, servers_online: int}
     */
    public function totals(BuilderContract|array $servers): array
    {
        $row = $this->totalsQuery($servers)->first();

        return [
            'players' => (int) ($row->players ?? 0),
            'servers_online' => (int) ($row->servers_online ?? 0),
        ];
    }

    /**
     * Last 24 hours, one slot per poll interval, players online summed over $servers. Slots
     * where no server was online (or none was polled) are null, so the chart draws a gap. One
     * query.
     *
     * @param  BuilderContract|list<int>  $servers
     * @return array{labels: list<string>, data: list<int|null>}
     */
    public function combinedSeries(BuilderContract|array $servers): array
    {
        $range = ActivityRange::Day;
        [$first, $length, $count] = $this->rawSlots($range);

        $data = array_fill(0, $count, null);
        foreach ($this->combinedSeriesQuery($servers, CarbonImmutable::createFromTimestampUTC($first))->get() as $row) {
            $slot = intdiv(Carbon::parse($row->polled_at, 'UTC')->getTimestamp() - $first, $length);
            if ($slot >= 0 && $slot < $count) {
                // polled_at is the slot start, so one row per slot unless the interval changed.
                $data[$slot] = max($data[$slot] ?? 0, (int) $row->online);
            }
        }

        return ['labels' => $this->rawLabels($first, $length, $count, $range), 'data' => $data];
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
