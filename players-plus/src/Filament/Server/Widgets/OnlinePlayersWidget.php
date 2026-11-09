<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Route;

/** Console widget: online count from the newest stored sample. Never queries the server. */
class OnlinePlayersWidget extends StatsOverviewWidget
{
    // Re-reads the database only; the poll job is what talks to the server.
    protected ?string $pollingInterval = '60s';

    public ?Server $server = null;

    public static function canView(): bool
    {
        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && Minecraft::isMinecraft($server)
            && $user->can(SubuserPermission::WebsocketConnect, $server);
    }

    protected function getStats(): array
    {
        $server = $this->server ?? Filament::getTenant();
        $sample = $server instanceof Server ? Sample::latestFor($server) : null;

        $stat = Stat::make(trans('players-plus::strings.widget.label'), match ($sample?->status) {
            null => trans('players-plus::strings.widget.no_data'),
            SampleStatus::Online => PlayerList::count($sample),
            SampleStatus::Offline => trans('players-plus::strings.status.offline'),
            SampleStatus::Unreachable => trans('players-plus::strings.status.unreachable'),
        })->icon('tabler-users');

        if ($sample !== null) {
            $stat->description(trans('players-plus::strings.page.updated', ['time' => $sample->polled_at->diffForHumans()]))
                ->color($sample->status->getColor());
        }

        if (PlayersPage::canAccess() && Route::has(PlayersPage::getRouteName())) {
            $stat->url(PlayersPage::getUrl());
        }

        return [$stat];
    }
}
