<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Widgets;

use DrMxrcy\PlayersPlus\Filament\Server\Pages\ActivityPage;
use DrMxrcy\PlayersPlus\Support\ActivityViewer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Peaks and the 7-day average on the Activity page. Offline time is not counted as 0. */
class ActivityStatsWidget extends StatsOverviewWidget
{
    // Rendered with the page, so one page load is one bounded set of queries.
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return ActivityPage::canAccess();
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $peaks = ActivityViewer::stats()?->peaks() ?? ['today' => null, 'week' => null, 'all_time' => null, 'average_7d' => null];
        $none = trans('players-plus::strings.activity.none');

        return [
            Stat::make(trans('players-plus::strings.activity.peak_today'), $peaks['today'] ?? $none)
                ->icon('tabler-sun'),
            Stat::make(trans('players-plus::strings.activity.peak_week'), $peaks['week'] ?? $none)
                ->icon('tabler-calendar-week'),
            Stat::make(trans('players-plus::strings.activity.peak_all_time'), $peaks['all_time'] ?? $none)
                ->description(trans('players-plus::strings.activity.peak_all_time_help'))
                ->icon('tabler-trophy'),
            Stat::make(trans('players-plus::strings.activity.average_7d'), $peaks['average_7d'] === null ? $none : number_format($peaks['average_7d'], 1))
                ->description(trans('players-plus::strings.activity.average_7d_help'))
                ->icon('tabler-chart-dots'),
        ];
    }
}
