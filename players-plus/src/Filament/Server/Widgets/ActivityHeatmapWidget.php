<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Widgets;

use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\ActivityPage;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use DrMxrcy\PlayersPlus\Support\ActivityViewer;
use Filament\Widgets\Widget;

/**
 * Weekday x hour grid of average online players over the last four weeks, in the viewer's
 * timezone. Hours with no online sample are empty cells, not zero.
 */
class ActivityHeatmapWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    // The players-plus:: view namespace is registered at runtime by the panel's plugin loader,
    // so static analysis can't resolve it.
    // @phpstan-ignore property.defaultValue
    protected string $view = 'players-plus::widgets.activity-heatmap';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ActivityPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $grid = ActivityViewer::stats()?->heatmap() ?? [];
        $values = array_filter(array_merge([], ...array_values($grid)), fn (?float $value) => $value !== null);
        $max = $values === [] ? 0.0 : max($values);
        // 2024-01-01 was a Monday.
        $monday = CarbonImmutable::create(2024, 1, 1)->locale(app()->getLocale());

        $rows = [];
        foreach ($grid as $day => $hours) {
            $name = $monday->addDays($day - 1)->isoFormat('ddd');
            $cells = [];
            foreach ($hours as $hour => $value) {
                $time = sprintf('%s %02d:00', $name, $hour);
                $cells[] = [
                    'value' => $value,
                    'title' => $value === null
                        ? trans('players-plus::strings.activity.heatmap_empty_cell', ['time' => $time])
                        : trans('players-plus::strings.activity.heatmap_cell', ['time' => $time, 'count' => number_format($value, 1)]),
                    // 15% to 100% of the primary colour, so the smallest non-empty value stays visible.
                    'strength' => match (true) {
                        $value === null => null,
                        $max <= 0 => 15,
                        default => (int) round(15 + 85 * $value / $max),
                    },
                ];
            }
            $rows[] = ['label' => $name, 'cells' => $cells];
        }

        return [
            'rows' => $rows,
            'heading' => trans('players-plus::strings.activity.heatmap_heading'),
            'description' => trans('players-plus::strings.activity.heatmap_description', ['days' => ActivityStats::HEATMAP_DAYS, 'timezone' => ActivityViewer::timezone()]),
        ];
    }
}
