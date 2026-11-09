<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Services\PlayerStats;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use Filament\Facades\Filament;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * One player of one server: identity, status, playtime, sessions and vanilla stats. Everything
 * but the stats comes from the database; the stats file is read through the daemon here, on
 * demand, and cached (PlayerStats). The game server is never contacted.
 */
class PlayerProfilePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'players-plus/player';

    protected static bool $shouldRegisterNavigation = false;

    /** ?player=<id>, read once on mount; later client changes are rejected. */
    #[Locked]
    #[Url]
    public ?string $player = null;

    /** Scoped to the current server on every request. */
    #[Locked]
    public int $playerId = 0;

    private ?Player $record = null;

    public static function canAccess(): bool
    {
        return PlayersPage::canAccess();
    }

    public function mount(): void
    {
        $id = $this->player;
        abort_unless(is_string($id) && ctype_digit($id) && strlen($id) < 19, 404);

        $this->playerId = (int) $id;
        $this->playerRecord();
    }

    public function playerRecord(): Player
    {
        if ($this->record === null) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $player = Player::query()->where('server_id', $server->id)->whereKey($this->playerId)->first();
            abort_if($player === null, 404);
            $this->record = $player;
        }

        return $this->record;
    }

    public function getTitle(): string
    {
        return $this->playerRecord()->name;
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PlayersPage::getUrl(['tab' => PlayersPage::TAB_ALL]) => trans('players-plus::strings.tabs.all'),
            $this->playerRecord()->name,
        ];
    }

    private function canReadStats(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        // The stats file is a server file: only users who could open it themselves see it.
        return user()?->can(SubuserPermission::FileRead, $server) ?? false;
    }

    /** @return array{state: string, stats: array<string, int|null>|null} */
    private function stats(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return app(PlayerStats::class)->forPlayer($server, $this->playerRecord());
    }

    public function content(Schema $schema): Schema
    {
        $player = $this->playerRecord();
        $open = $player->openSession();

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make(trans('players-plus::strings.profile.identity'))
                    ->icon('tabler-id')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('avatar')
                            ->hiddenLabel()
                            ->state(PlayerList::avatarUrl($player->name, $player->uuid))
                            ->imageSize(64)
                            ->columnSpanFull(),
                        TextEntry::make('name')
                            ->label(trans('players-plus::strings.page.name'))
                            ->state($player->name)
                            ->copyable(),
                        TextEntry::make('uuid')
                            ->label(trans('players-plus::strings.profile.uuid'))
                            ->state($player->uuid ?? trans('players-plus::strings.profile.uuid_unknown'))
                            ->copyable($player->uuid !== null)
                            ->fontFamily('mono'),
                        TextEntry::make('previous_names')
                            ->label(trans('players-plus::strings.profile.previous_names'))
                            ->state(array_reverse($player->previous_names ?? []) ?: [trans('players-plus::strings.profile.none')])
                            ->badge(($player->previous_names ?? []) !== [])
                            ->color('gray')
                            ->columnSpanFull(),
                    ]),
                Section::make(trans('players-plus::strings.profile.activity'))
                    ->icon('tabler-clock')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label(trans('players-plus::strings.profile.status'))
                            ->state($open !== null
                                ? trans('players-plus::strings.all.online')
                                : trans('players-plus::strings.profile.last_seen', ['time' => $player->last_seen_at->diffForHumans()]))
                            ->badge()
                            ->color($open !== null ? 'success' : 'gray')
                            ->tooltip($open?->started_at->toDayDateTimeString() ?? $player->last_seen_at->toDayDateTimeString()),
                        TextEntry::make('first_seen')
                            ->label(trans('players-plus::strings.all.first_seen'))
                            ->state($player->first_seen_at->diffForHumans())
                            ->tooltip($player->first_seen_at->toDayDateTimeString()),
                        TextEntry::make('total')
                            ->label(trans('players-plus::strings.profile.total_playtime'))
                            ->state(PlayerList::duration($player->playtime())),
                        TextEntry::make('week')
                            ->label(trans('players-plus::strings.profile.week_playtime'))
                            ->state(PlayerList::duration($player->playtimeSince(now()->subDays(7)))),
                    ]),
            ]),
            $this->statsSection(),
            EmbeddedTable::make(),
        ]);
    }

    private function statsSection(): Section
    {
        $section = Section::make(trans('players-plus::strings.stats.heading'))
            ->icon('tabler-chart-bar')
            ->description(trans('players-plus::strings.stats.description'))
            ->collapsible();

        if (!$this->canReadStats()) {
            return $section->schema([Text::make(trans('players-plus::strings.stats.no_permission'))->color('gray')]);
        }

        $result = $this->stats();
        if ($result['state'] === PlayerStats::STATE_ERROR) {
            return $section->schema([Text::make(trans('players-plus::strings.stats.unavailable'))->color('warning')]);
        }
        if ($result['stats'] === null) {
            return $section->schema([Text::make(trans('players-plus::strings.stats.none'))->color('gray')]);
        }

        $stats = $result['stats'];
        $count = fn (string $key) => $stats[$key] === null ? '—' : number_format($stats[$key]);

        return $section->columns(['default' => 2, 'md' => 3, 'xl' => 6])->schema([
            TextEntry::make('stat_play_time')->label(trans('players-plus::strings.stats.play_time'))
                ->state($stats['play_time'] === null ? '—' : PlayerList::duration($stats['play_time'])),
            TextEntry::make('stat_deaths')->label(trans('players-plus::strings.stats.deaths'))->state($count('deaths')),
            TextEntry::make('stat_mob_kills')->label(trans('players-plus::strings.stats.mob_kills'))->state($count('mob_kills')),
            TextEntry::make('stat_player_kills')->label(trans('players-plus::strings.stats.player_kills'))->state($count('player_kills')),
            TextEntry::make('stat_distance_walked')->label(trans('players-plus::strings.stats.distance_walked'))
                ->state($stats['distance_walked'] === null ? '—' : self::distance($stats['distance_walked'])),
            TextEntry::make('stat_jumps')->label(trans('players-plus::strings.stats.jumps'))->state($count('jumps')),
        ]);
    }

    private static function distance(int $centimeters): string
    {
        return $centimeters >= 100_000
            ? number_format($centimeters / 100_000, 2) . ' km'
            : number_format(intdiv($centimeters, 100)) . ' m';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PlayerSession::query()->where('player_id', $this->playerRecord()->id))
            ->heading(trans('players-plus::strings.profile.sessions'))
            ->defaultSort('started_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('started_at')
                    ->label(trans('players-plus::strings.profile.started'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label(trans('players-plus::strings.profile.ended'))
                    ->placeholder(trans('players-plus::strings.all.online'))
                    ->dateTime(),
                TextColumn::make('duration')
                    ->label(trans('players-plus::strings.profile.duration'))
                    ->state(fn (PlayerSession $record) => PlayerList::duration($record->seconds())),
                TextColumn::make('source')
                    ->label(trans('players-plus::strings.profile.source'))
                    ->formatStateUsing(fn (PlayerSession $record) => $record->source->getLabel())
                    ->badge()
                    ->color('gray'),
            ])
            ->emptyStateIcon('tabler-clock-off')
            ->emptyStateHeading(trans('players-plus::strings.profile.no_sessions'))
            ->emptyStateDescription(null);
    }
}
