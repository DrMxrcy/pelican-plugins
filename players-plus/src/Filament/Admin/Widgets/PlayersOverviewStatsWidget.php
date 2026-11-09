<?php

namespace DrMxrcy\PlayersPlus\Filament\Admin\Widgets;

use DrMxrcy\PlayersPlus\Filament\Admin\Pages\PlayersOverviewPage;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Totals on the admin Players overview: players online now on live Minecraft servers. */
class PlayersOverviewStatsWidget extends StatsOverviewWidget
{
    // Rendered with the page, so one page load is one bounded set of queries.
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return PlayersOverviewPage::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /** Its own Livewire requests are gated too, not only the page that embeds it. */
    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $totals = ActivityStats::acrossServers()->totals(PlayersOverviewPage::serversQuery()->select('id'));

        return [
            Stat::make(trans('players-plus::strings.overview.total_online'), (string) $totals['players'])
                ->description(trans('players-plus::strings.overview.total_online_help'))
                ->icon('tabler-users'),
            Stat::make(trans('players-plus::strings.overview.servers_online'), (string) $totals['servers_online'])
                ->icon('tabler-server'),
        ];
    }
}
