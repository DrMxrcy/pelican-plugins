<?php

namespace DrMxrcy\PlayersPlus\Console\Commands;

use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Sample;
use Illuminate\Console\Command;

/**
 * Rolls raw samples up into one pp_hourly row per server and hour. Each hour is aggregated in
 * SQL and upserted on (server_id, hour_start), so running it again gives the same rows.
 */
class RollupCommand extends Command
{
    public const MAX_HOURS = 24 * 31;

    protected $signature = 'players-plus:rollup {--hours=2 : Hours to roll up, counting back from the current one}';

    protected $description = 'Roll Players Plus samples up into hourly rows';

    public function handle(): int
    {
        $hours = max(1, min(self::MAX_HOURS, (int) $this->option('hours')));
        $current = CarbonImmutable::now('UTC')->startOfHour();
        $written = 0;

        for ($i = $hours - 1; $i >= 0; $i--) {
            $written += self::rollupHour($current->subHours($i));
        }

        $this->info("Rolled up $hours hour(s), $written row(s).");

        return self::SUCCESS;
    }

    /** Aggregates one UTC hour for every server and upserts the rows. Returns the row count. */
    public static function rollupHour(CarbonImmutable $hourStart): int
    {
        $start = $hourStart->utc()->startOfHour();
        $online = SampleStatus::Online->value;
        // An online sample without a count can't be averaged; it only counts as a sample.
        $isOnline = 'status = ? AND online IS NOT NULL';

        $rows = Sample::query()->toBase()
            ->where('polled_at', '>=', $start)
            ->where('polled_at', '<', $start->addHour())
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('COUNT(*) AS samples')
            ->selectRaw("SUM(CASE WHEN $isOnline THEN 1 ELSE 0 END) AS online_samples", [$online])
            ->selectRaw("AVG(CASE WHEN $isOnline THEN online END) AS avg_online", [$online])
            ->selectRaw("MAX(CASE WHEN $isOnline THEN online END) AS max_online", [$online])
            ->get()
            ->map(fn (object $row) => [
                'server_id' => (int) $row->server_id,
                'hour_start' => $start->toDateTimeString(),
                'epoch_hour' => intdiv($start->getTimestamp(), 3600),
                'avg_online' => $row->avg_online === null ? null : (float) $row->avg_online,
                'max_online' => $row->max_online === null ? null : (int) $row->max_online,
                'samples' => (int) $row->samples,
                'online_samples' => (int) $row->online_samples,
            ])
            ->all();

        if ($rows === []) {
            return 0;
        }

        HourlyRollup::query()->upsert($rows, ['server_id', 'hour_start'], ['epoch_hour', 'avg_online', 'max_online', 'samples', 'online_samples']);

        return count($rows);
    }
}
