<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use DrMxrcy\PlayersPlus\Support\Settings;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * "Online now" for one Minecraft server, rendered from the newest stored sample. Opening or
 * refreshing this page never queries the game server.
 */
class PlayersPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users';

    // Not "players": other player plugins may already use that route.
    protected static ?string $slug = 'players-plus';

    protected static ?int $navigationSort = 31;

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

        return $schema->components([
            Callout::make(trans('players-plus::strings.page.no_data_title'))
                ->description(trans('players-plus::strings.page.no_data_body', ['minutes' => Settings::pollInterval()]))
                ->icon('tabler-clock')
                ->color('gray')
                ->visible(fn () => $this->sample() === null),
            Callout::make(trans('players-plus::strings.page.offline_title'))
                ->description(fn () => trans('players-plus::strings.page.offline_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-player-stop')
                ->color('gray')
                ->visible(fn () => $status() === SampleStatus::Offline),
            Callout::make(trans('players-plus::strings.page.unreachable_title'))
                ->description(fn () => trans('players-plus::strings.page.unreachable_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-plug-connected-x')
                ->color('warning')
                ->visible(fn () => $status() === SampleStatus::Unreachable),
            Callout::make(trans('players-plus::strings.page.stale_title'))
                ->description(fn () => trans('players-plus::strings.page.stale_body', ['time' => $this->sample()?->polled_at->diffForHumans()]))
                ->icon('tabler-alert-triangle')
                ->color('warning')
                ->visible(fn () => ($sample = $this->sample()) !== null && Settings::enabled() && PlayerList::isStale($sample)),
            EmbeddedTable::make()
                ->visible(fn () => $status() === SampleStatus::Online),
        ]);
    }
}
