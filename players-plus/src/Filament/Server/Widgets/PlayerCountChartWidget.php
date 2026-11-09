<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Widgets;

use DrMxrcy\PlayersPlus\Enums\ActivityRange;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\ActivityPage;
use DrMxrcy\PlayersPlus\Support\ActivityViewer;
use Filament\Widgets\ChartWidget;

/**
 * Player count over 24 h (raw samples) or 7 d / 30 d (hourly averages). Offline, unreachable
 * and unpolled slots are null, and spanGaps is off, so Chart.js draws gaps rather than zeros.
 */
class PlayerCountChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = ActivityRange::Day->value;

    public static function canView(): bool
    {
        return ActivityPage::canAccess();
    }

    public function getHeading(): string
    {
        return trans('players-plus::strings.activity.chart_heading');
    }

    public function getDescription(): string
    {
        return trans('players-plus::strings.activity.chart_gaps');
    }

    public function range(): ActivityRange
    {
        return ActivityRange::tryFrom((string) $this->filter) ?? ActivityRange::Day;
    }

    /** @return array<string, string> */
    protected function getFilters(): array
    {
        $filters = [];
        foreach (ActivityRange::cases() as $range) {
            $filters[$range->value] = $range->getLabel();
        }

        return $filters;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $range = $this->range();
        $series = ActivityViewer::stats()?->series($range) ?? ['labels' => [], 'data' => []];

        return [
            'datasets' => [
                [
                    'label' => $range->readsRawSamples() ? trans('players-plus::strings.activity.series_online') : trans('players-plus::strings.activity.series_average'),
                    'data' => $series['data'],
                    'spanGaps' => false,
                    'fill' => true,
                    'tension' => 0.2,
                    'pointRadius' => 0,
                    'pointHitRadius' => 6,
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            // An object, not false: Filament's chart.js sets options.animation.duration.
            'animation' => ['duration' => 0],
            'spanGaps' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['ticks' => ['autoSkip' => true, 'maxTicksLimit' => 12, 'maxRotation' => 0]],
            ],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
