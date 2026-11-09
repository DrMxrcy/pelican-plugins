<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Concerns\ModeratesPlayers;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\ModerationLists;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use DrMxrcy\PlayersPlus\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
 * players" from the stored player records; opening or refreshing those two tabs never
 * queries the game server or the daemon. The Banned, Operators and Whitelist tabs (users
 * with file.read-content only) read the server's list files through the daemon, cached.
 */
class PlayersPage extends Page implements HasTable
{
    use InteractsWithTable;
    use ModeratesPlayers;

    public const TAB_ONLINE = 'online';

    public const TAB_ALL = 'all';

    public const TAB_BANNED = ModerationLists::BANNED;

    public const TAB_OPS = ModerationLists::OPS;

    public const TAB_WHITELIST = ModerationLists::WHITELIST;

    private const LIST_TABS = [self::TAB_BANNED, self::TAB_OPS, self::TAB_WHITELIST];

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

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->whitelistByNameAction()];
    }

    /** The player name of a row: an online entry, a stored Player or a list entry. */
    private static function recordName(mixed $record): mixed
    {
        return $record instanceof Player ? $record->name : (is_array($record) ? ($record['name'] ?? null) : null);
    }

    /**
     * Row moderation actions, in a menu.
     *
     * @param  list<ModerationAction>  $actions
     */
    private function moderationGroup(array $actions): ActionGroup
    {
        return ActionGroup::make(array_map(fn (ModerationAction $action) => $this->moderationAction($action, self::recordName(...)), $actions))
            ->label(trans('players-plus::strings.moderation.group'))
            ->icon('tabler-gavel')
            ->color('gray')
            ->visible(fn () => static::canModerate());
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
        $this->resetTable();
    }

    private function normalizeTab(): void
    {
        $tabs = [self::TAB_ONLINE, self::TAB_ALL, ...(self::canViewLists() ? self::LIST_TABS : [])];
        if (!in_array($this->tab, $tabs, true)) {
            $this->tab = self::TAB_ONLINE;
        }
    }

    private function showingAll(): bool
    {
        return $this->tab === self::TAB_ALL;
    }

    /** The list tab being shown, or null; checked against the permission on every call. */
    private function showingList(): ?string
    {
        return in_array($this->tab, self::LIST_TABS, true) && self::canViewLists() ? $this->tab : null;
    }

    /** The list files are server files: only users who could open them themselves see them. */
    public static function canViewLists(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && (user()?->can(SubuserPermission::FileReadContent, $server) ?? false);
    }

    /**
     * @param  ModerationLists::BANNED|ModerationLists::OPS|ModerationLists::WHITELIST  $list
     * @return array{state: string, entries: list<array{name: string, uuid: string|null, reason: string|null, source: string|null, created: int|null, expires: int|null, level: int|null}>}
     */
    public function listState(string $list): array
    {
        if (!self::canViewLists()) {
            return ['state' => ModerationLists::STATE_ERROR, 'entries' => []];
        }

        if (!isset($this->moderationListStates[$list])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->moderationListStates[$list] = ModerationLists::read($server, $list);
        }

        return $this->moderationListStates[$list];
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
        $list = $this->showingList();
        if ($list !== null) {
            return $this->listTable($table, $list);
        }

        return $this->showingAll() ? $this->allPlayersTable($table) : $this->onlineTable($table);
    }

    /**
     * @param  ModerationLists::BANNED|ModerationLists::OPS|ModerationLists::WHITELIST  $list
     */
    private function listTable(Table $table, string $list): Table
    {
        $date = fn (?int $timestamp) => $timestamp === null ? null : Carbon::createFromTimestamp($timestamp);

        $columns = [
            ImageColumn::make('avatar')
                ->label('')
                ->state(fn (array $record) => PlayerList::avatarUrl($record['name'], $record['uuid']))
                ->imageSize(32)
                ->grow(false),
            TextColumn::make('name')
                ->label(trans('players-plus::strings.page.name'))
                ->description(fn (array $record) => $record['uuid']),
        ];

        if ($list === ModerationLists::BANNED) {
            $columns[] = TextColumn::make('reason')
                ->label(trans('players-plus::strings.lists.reason'))
                ->placeholder('—')
                ->wrap();
            $columns[] = TextColumn::make('source')
                ->label(trans('players-plus::strings.lists.source'))
                ->placeholder('—');
            $columns[] = TextColumn::make('created')
                ->label(trans('players-plus::strings.lists.banned_at'))
                ->state(fn (array $record) => $date($record['created']))
                ->since()
                ->dateTimeTooltip()
                ->placeholder('—');
            $columns[] = TextColumn::make('expires')
                ->label(trans('players-plus::strings.lists.expires'))
                ->state(fn (array $record) => $date($record['expires']))
                ->dateTime()
                ->placeholder(trans('players-plus::strings.lists.forever'));
        }

        if ($list === ModerationLists::OPS) {
            $columns[] = TextColumn::make('level')
                ->label(trans('players-plus::strings.lists.level'))
                ->placeholder('—')
                ->badge()
                ->color('gray');
        }

        $remove = match ($list) {
            ModerationLists::BANNED => ModerationAction::Pardon,
            ModerationLists::OPS => ModerationAction::Deop,
            ModerationLists::WHITELIST => ModerationAction::WhitelistRemove,
        };

        return $table
            ->recordActions([$this->moderationAction($remove, self::recordName(...))])
            ->records(function () use ($list) {
                $rows = [];
                foreach ($this->listState($list)['entries'] as $entry) {
                    $rows[strtolower($entry['name'])] ??= $entry;
                }

                return $rows;
            })
            ->heading(trans("players-plus::strings.lists.$list.heading"))
            ->description(trans('players-plus::strings.lists.description', ['file' => ModerationLists::FILES[$list], 'seconds' => ModerationLists::CACHE_SECONDS]))
            ->paginated(false)
            ->columns($columns)
            ->emptyStateIcon('tabler-list')
            ->emptyStateHeading(trans("players-plus::strings.lists.$list.empty"))
            ->emptyStateDescription(null);
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
            ->recordActions([$this->moderationGroup([
                ModerationAction::Kick, ModerationAction::Ban, ModerationAction::Op, ModerationAction::Deop,
                ModerationAction::WhitelistAdd, ModerationAction::WhitelistRemove,
            ])])
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
            ->description(fn () => ($sample = $this->sample()) !== null && PlayerList::isIncomplete($sample) ? trans('players-plus::strings.all.partial') : null)
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
                    ->badge(fn (Player $record) => $this->isOnline($record))
                    ->color(fn (Player $record) => $this->isOnline($record) ? 'success' : null)
                    ->formatStateUsing(fn (Player $record) => $this->isOnline($record)
                        ? trans('players-plus::strings.all.online')
                        : $record->last_seen_at->diffForHumans())
                    ->tooltip(fn (Player $record) => $record->last_seen_at->toDayDateTimeString())
                    ->sortable(),
                // Sorts by closed-session playtime; the shown value adds the open session, so a
                // player online right now can sort slightly below their displayed total.
                TextColumn::make('total_seconds')
                    ->label(trans('players-plus::strings.all.playtime'))
                    ->formatStateUsing(fn (Player $record) => PlayerList::duration($this->playtime($record)))
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
            ->recordActions([$this->moderationGroup(ModerationAction::cases())])
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

    /** Online only while polling is live; a stale or offline server shows last seen instead. */
    private function isOnline(Player $player): bool
    {
        return self::openSince($player) !== null && PlayerList::isLive($this->sample());
    }

    /** Closed sessions plus the open one, counted to now while live, else to the last sighting. */
    private function playtime(Player $player): int
    {
        $open = self::openSince($player);
        if ($open === null) {
            return $player->total_seconds;
        }
        $until = PlayerList::isLive($this->sample()) ? now() : $player->last_seen_at;

        return $player->total_seconds + max(0, $until->getTimestamp() - $open->getTimestamp());
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
        $online = fn () => $this->tab === self::TAB_ONLINE;
        $listError = fn () => ($list = $this->showingList()) !== null && $this->listState($list)['state'] === ModerationLists::STATE_ERROR;

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
                    self::TAB_BANNED => Tab::make(self::TAB_BANNED)
                        ->label(trans('players-plus::strings.tabs.banned'))
                        ->icon('tabler-ban')
                        ->visible(fn () => self::canViewLists()),
                    self::TAB_OPS => Tab::make(self::TAB_OPS)
                        ->label(trans('players-plus::strings.tabs.ops'))
                        ->icon('tabler-shield-star')
                        ->visible(fn () => self::canViewLists()),
                    self::TAB_WHITELIST => Tab::make(self::TAB_WHITELIST)
                        ->label(trans('players-plus::strings.tabs.whitelist'))
                        ->icon('tabler-list-check')
                        ->visible(fn () => self::canViewLists()),
                ]),
            Callout::make(trans('players-plus::strings.lists.error_title'))
                ->description(fn () => trans('players-plus::strings.lists.error_body', ['file' => ModerationLists::FILES[$this->showingList() ?? ModerationLists::OPS]]))
                ->icon('tabler-file-alert')
                ->color('danger')
                ->visible($listError),
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
