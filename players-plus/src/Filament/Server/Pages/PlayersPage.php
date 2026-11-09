<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use DrMxrcy\PlayersPlus\Support\Settings;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Players of one Minecraft server: "Online now" from the newest stored sample, and "All
 * players" from the stored player records. Opening or refreshing this page never queries
 * the game server or the daemon.
 */
class PlayersPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const TAB_ONLINE = 'online';

    public const TAB_ALL = 'all';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users';

    // Not "players": other player plugins may already use that route.
    protected static ?string $slug = 'players-plus';

    protected static ?int $navigationSort = 31;

    #[Url]
    public string $tab = self::TAB_ONLINE;

    private ?Sample $sample = null;

    private bool $sampleLoaded = false;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        $user = user();

        return parent::canAccess()
            && $server instanceof Server
            && $user !== null
            && Minecraft::isMinecraft($server)
            // Joins and leaves are already visible to anyone who can read the console.
            && $user->can(SubuserPermission::WebsocketConnect, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('players-plus::strings.navigation');
    }

    public function getTitle(): string
    {
        return trans('players-plus::strings.page.title');
    }

    public function mount(): void
    {
        $this->normalizeTab();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
        $this->resetTable();
    }

    private function normalizeTab(): void
    {
        if (!in_array($this->tab, [self::TAB_ONLINE, self::TAB_ALL], true)) {
            $this->tab = self::TAB_ONLINE;
        }
    }

    private function showingAll(): bool
    {
        return $this->tab === self::TAB_ALL;
    }

    public function sample(): ?Sample
    {
        if (!$this->sampleLoaded) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->sample = Sample::latestFor($server);
            $this->sampleLoaded = true;
        }

        return $this->sample;
    }

    public function table(Table $table): Table
    {
        return $this->showingAll() ? $this->allPlayersTable($table) : $this->onlineTable($table);
    }

    private function onlineTable(Table $table): Table
    {
        return $table
            ->records(fn () => PlayerList::rows($this->sample()))
            ->heading(trans('players-plus::strings.page.online_now'))
            ->description(fn () => $this->summary())
            ->paginated(false)
            ->poll('60s')
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->imageSize(32)
                    ->grow(false),
                TextColumn::make('name')
                    ->label(trans('players-plus::strings.page.name'))
                    ->description(fn (array $record) => $record['uuid'])
                    ->searchable(false),
            ])
            ->emptyStateIcon('tabler-user-off')
            ->emptyStateHeading(trans('players-plus::strings.page.empty'))
            ->emptyStateDescription(null);
    }

    private function allPlayersTable(Table $table): Table
    {
        return $table
            ->query(function () {
                /** @var Server $server */
                $server = Filament::getTenant();

                return Player::query()
                    ->where('server_id', $server->id)
                    ->withCount('sessions')
                    ->withMin(['sessions as open_since' => fn (Builder $query) => $query->whereNull('ended_at')], 'started_at');
            })
            ->heading(trans('players-plus::strings.all.heading'))
            ->description(fn () => ($sample = $this->sample()) !== null && PlayerList::isPartial($sample) ? trans('players-plus::strings.all.partial') : null)
            ->defaultSort('last_seen_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (Player $record) => PlayerProfilePage::getUrl(['player' => $record->id]))
            ->poll('60s')
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (Player $record) => PlayerList::avatarUrl($record->name, $record->uuid))
                    ->imageSize(32)
                    ->grow(false),
                TextColumn::make('name')
                    ->label(trans('players-plus::strings.page.name'))
                    ->description(fn (Player $record) => $record->uuid)
                    ->searchable(['name', 'uuid'])
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label(trans('players-plus::strings.all.last_seen'))
                    ->badge(fn (Player $record) => self::openSince($record) !== null)
                    ->color(fn (Player $record) => self::openSince($record) !== null ? 'success' : null)
                    ->formatStateUsing(fn (Player $record) => self::openSince($record) !== null
                        ? trans('players-plus::strings.all.online')
                        : $record->last_seen_at->diffForHumans())
                    ->tooltip(fn (Player $record) => $record->last_seen_at->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('total_seconds')
                    ->label(trans('players-plus::strings.all.playtime'))
                    ->formatStateUsing(fn (Player $record) => PlayerList::duration(self::playtime($record)))
                    ->sortable(),
                TextColumn::make('sessions_count')
                    ->label(trans('players-plus::strings.all.sessions'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label(trans('players-plus::strings.all.first_seen'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->emptyStateIcon('tabler-users')
            ->emptyStateHeading(trans('players-plus::strings.all.empty'))
            ->emptyStateDescription(null);
    }

    /** Start of the player's open session, loaded by the All players query. */
    private static function openSince(Player $player): ?Carbon
    {
        $value = $player->getAttribute('open_since');

        return $value === null ? null : Carbon::parse($value);
    }

    /** Closed sessions plus the open one, counted live. */
    private static function playtime(Player $player): int
    {
        $open = self::openSince($player);

        return $player->total_seconds + ($open === null ? 0 : max(0, now()->getTimestamp() - $open->getTimestamp()));
    }

    private function summary(): ?string
    {
        $sample = $this->sample();
        if ($sample === null) {
            return null;
        }

        $parts = [PlayerList::count($sample), trans('players-plus::strings.page.updated', ['time' => $sample->polled_at->diffForHumans()])];
        if (PlayerList::isPartial($sample)) {
            $parts[] = trans('players-plus::strings.page.partial', ['limit' => PlayerList::PING_SAMPLE_LIMIT]);
        }

        return implode(' · ', $parts);
    }

    public function content(Schema $schema): Schema
    {
        $status = fn () => $this->sample()?->status;
        $online = fn () => !$this->showingAll();

        return $schema->components([
            Tabs::make(trans('players-plus::strings.page.title'))
                ->key('players-plus-tabs')
                ->livewireProperty('tab')
                ->tabPanels(false)
                ->contained(false)
                ->tabs([
                    self::TAB_ONLINE => Tab::make(self::TAB_ONLINE)
                        ->label(trans('players-plus::strings.tabs.online'))
                        ->icon('tabler-user-check'),
                    self::TAB_ALL => Tab::make(self::TAB_ALL)
                        ->label(trans('players-plus::strings.tabs.all'))
                        ->icon('tabler-users'),
                ]),
            Callout::make(trans('players-plus::strings.page.no_data_title'))
                ->description(trans('players-plus::strings.page.no_data_body', ['minutes' => Settings::pollInterval()]))
                ->icon('tabler-clock')
                ->color('gray')
                ->visible(fn () => $online() && $this->sample() === null),
            Callout::make(trans('players-plus::strings.page.offline_title'))
                ->description(fn () => trans('players-plus::strings.page.offline_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-player-stop')
                ->color('gray')
                ->visible(fn () => $online() && $status() === SampleStatus::Offline),
            Callout::make(trans('players-plus::strings.page.unreachable_title'))
                ->description(fn () => trans('players-plus::strings.page.unreachable_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-plug-connected-x')
                ->color('warning')
                ->visible(fn () => $online() && $status() === SampleStatus::Unreachable),
            Callout::make(trans('players-plus::strings.page.stale_title'))
                ->description(fn () => trans('players-plus::strings.page.stale_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-alert-triangle')
                ->color('warning')
                ->visible(fn () => ($sample = $this->sample()) !== null && Settings::enabled() && PlayerList::isStale($sample)),
            EmbeddedTable::make()
                ->visible(fn () => !$online() || $status() === SampleStatus::Online),
        ]);
    }
}
