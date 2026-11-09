<?php

namespace DrMxrcy\PlayersPlus\Filament\Admin\Widgets;

use DrMxrcy\PlayersPlus\Filament\Admin\Pages\PlayersOverviewPage;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use DrMxrcy\PlayersPlus\Support\ActivityViewer;
use Filament\Widgets\ChartWidget;

/**
 * Players online over the last 24 hours, summed over every Minecraft server, one point per poll
 * slot. Slots where no server was online are null and spanGaps is off, so they show as gaps.
 */
class PlayersOverviewChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return PlayersOverviewPage::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        parent::mount();
    }

    /** Its own Livewire requests are gated too, not only the page that embeds it. */
    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function getHeading(): string
    {
        return trans('players-plus::strings.overview.chart_heading');
    }

    public function getDescription(): string
    {
        return trans('players-plus::strings.overview.chart_description');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = ActivityStats::acrossServers(ActivityViewer::timezone())->combinedSeries(PlayersOverviewPage::serversQuery()->select('id'));

        return [
            'datasets' => [
                [
                    'label' => trans('players-plus::strings.overview.series'),
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
