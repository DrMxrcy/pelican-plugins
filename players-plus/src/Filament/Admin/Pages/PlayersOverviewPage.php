<?php

namespace DrMxrcy\PlayersPlus\Filament\Admin\Pages;

use App\Models\Server;
use DrMxrcy\PlayersPlus\Filament\Admin\Widgets\PlayersOverviewChartWidget;
use DrMxrcy\PlayersPlus\Filament\Admin\Widgets\PlayersOverviewStatsWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use DrMxrcy\PlayersPlus\Support\ActivityViewer;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\PollHealth;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * Admin panel: every Minecraft server with its player numbers, plus totals and a combined 24 h
 * chart. Reads stored samples, rollups and players only (ActivityStats grouped queries, one per
 * metric for the servers on the current table page); never contacts a game server.
 *
 * @phpstan-import-type OverviewRow from ActivityStats
 */
class PlayersOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** Role model and permission in the panel's role editor ("View" under "Players Plus"). */
    public const PERMISSION_MODEL = 'players-plus';

    public const PERMISSION = 'view ' . self::PERMISSION_MODEL;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users';

    // Not "players": other player plugins may already use that route.
    protected static ?string $slug = 'players-plus';

    protected static ?int $navigationSort = 31;

    /** @var array<int, OverviewRow>|null rows for the servers on the current table page */
    private ?array $rows = null;

    /**
     * Admin panel only, with the permission; root admins pass through the panel's Gate::before.
     * In the server panel (or any other) this page does not exist, even for a root admin.
     */
    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (user()?->can(self::PERMISSION) ?? false);
    }

    /**
     * The Minecraft servers this admin may see: limited to the nodes their roles allow, the same
     * rule as the panel's own server list (ServerResource::getEloquentQuery). The node filter is
     * a subquery, so it adds no query. No user: nothing.
     *
     * @return Builder<Server>
     */
    public static function serversQuery(): Builder
    {
        $user = user();
        $query = Minecraft::serversQuery();

        return $user === null
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('node_id', $user->accessibleNodes()->select('id'));
    }

    public static function getNavigationLabel(): string
    {
        return trans('players-plus::strings.overview.navigation');
    }

    public function getTitle(): string
    {
        return trans('players-plus::strings.overview.title');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PlayersOverviewStatsWidget::class,
            PlayersOverviewChartWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        $none = trans('players-plus::strings.overview.none');

        return $table
            ->query(fn () => self::serversQuery())
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (Server $record) => self::playersUrl($record))
            ->columns([
                TextColumn::make('name')
                    ->label(trans('players-plus::strings.overview.server'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(trans('players-plus::strings.overview.status'))
                    ->state(fn (Server $record) => $this->statusLabel($this->row($record)))
                    ->badge()
                    ->color(fn (Server $record) => $this->statusColor($this->row($record))),
                TextColumn::make('online_now')
                    ->label(trans('players-plus::strings.overview.online_now'))
                    ->state(function (Server $record) use ($none) {
                        $row = $this->row($record);

                        return $row['live'] ? trans('players-plus::strings.page.count', ['online' => $row['online'], 'max' => $row['max'] ?? '?']) : $none;
                    }),
                TextColumn::make('peak_24h')
                    ->label(trans('players-plus::strings.overview.peak_24h'))
                    ->state(fn (Server $record) => (string) ($this->row($record)['peak_24h'] ?? $none)),
                TextColumn::make('average_7d')
                    ->label(trans('players-plus::strings.overview.average_7d'))
                    ->tooltip(trans('players-plus::strings.activity.average_7d_help'))
                    ->state(function (Server $record) use ($none) {
                        $average = $this->row($record)['average_7d'];

                        return $average === null ? $none : number_format($average, 1);
                    }),
                TextColumn::make('unique_7d')
                    ->label(trans('players-plus::strings.overview.unique_7d'))
                    ->state(fn (Server $record) => (string) $this->row($record)['unique_7d']),
                TextColumn::make('last_poll')
                    ->label(trans('players-plus::strings.overview.last_poll'))
                    ->state(fn (Server $record) => $this->row($record)['polled_at']?->diffForHumans() ?? trans('players-plus::strings.overview.never'))
                    ->tooltip(fn (Server $record) => $this->row($record)['polled_at']?->setTimezone(ActivityViewer::timezone())->toDayDateTimeString()),
            ])
            ->emptyStateIcon('tabler-server-off')
            ->emptyStateHeading(trans('players-plus::strings.overview.empty'))
            ->emptyStateDescription(null);
    }

    /**
     * Overview numbers for one server. The first call loads them for every server on the current
     * table page at once (four grouped queries), so the page never runs a query per row.
     *
     * @return OverviewRow
     */
    private function row(Server $server): array
    {
        if ($this->rows === null) {
            $records = $this->getTableRecords();
            $servers = $records instanceof Paginator ? $records->items() : $records->all();
            $ids = array_values(array_map(fn (Server $record) => (int) $record->id, $servers));
            $this->rows = ActivityStats::acrossServers(ActivityViewer::timezone())->overview($ids);
        }

        return $this->rows[$server->id] ?? [
            'status' => null, 'online' => null, 'max' => null, 'polled_at' => null, 'live' => false,
            'peak_24h' => null, 'average_7d' => null, 'unique_7d' => 0,
        ];
    }

    /** @param  OverviewRow  $row */
    private function statusLabel(array $row): string
    {
        return match (true) {
            $row['status'] === null => trans('players-plus::strings.overview.no_data'),
            $row['polled_at'] === null || $row['polled_at']->lt(PollHealth::freshFrom()) => trans('players-plus::strings.overview.stale'),
            default => $row['status']->getLabel(),
        };
    }

    /** @param  OverviewRow  $row */
    private function statusColor(array $row): string
    {
        return match (true) {
            $row['status'] === null => 'gray',
            $row['polled_at'] === null || $row['polled_at']->lt(PollHealth::freshFrom()) => 'warning',
            default => $row['status']->getColor(),
        };
    }

    /** The server's Players page in the server panel, when that page is routed. */
    private static function playersUrl(Server $server): ?string
    {
        return Route::has(PlayersPage::getRouteName(Filament::getPanel('server'))) ? PlayersPage::getUrl(panel: 'server', tenant: $server) : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(array_values(array_filter([
            // Queue worker or scheduler not running: every number here is out of date.
            PollHealth::callout(),
            EmbeddedTable::make(),
        ])));
    }
}
