<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\ActivityHeatmapWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\ActivityStatsWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\PlayerCountChartWidget;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Player-count history of one Minecraft server: peaks, chart and weekday x hour heatmap. Reads
 * stored samples and hourly rollups only (ActivityStats); never contacts the game server.
 */
class ActivityPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-chart-line';

    protected static ?string $slug = 'players-plus-activity';

    protected static ?int $navigationSort = 32;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        $user = user();

        return parent::canAccess()
            && $server instanceof Server
            && $user !== null
            && Minecraft::isMinecraft($server)
            // Same gate as the Players page: counts are visible to anyone who can read the console.
            && $user->can(SubuserPermission::WebsocketConnect, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('players-plus::strings.activity.navigation');
    }

    public function getTitle(): string
    {
        return trans('players-plus::strings.activity.title');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ActivityStatsWidget::class,
            PlayerCountChartWidget::class,
            ActivityHeatmapWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
