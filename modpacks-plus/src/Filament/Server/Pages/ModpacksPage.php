<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use DrMxrcy\ModpacksPlus\Support\VersionMatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Browse Modrinth modpacks and install one onto this server (when its loader and
 * Minecraft version already match).
 *
 * Permissions: viewing needs file.read; installing and cancelling need
 * settings.reinstall + file.create + file.delete + backup.create. The public Livewire
 * methods installPack() and cancelInstall() check them themselves: they are callable from
 * the browser no matter which buttons are shown.
 */
class ModpacksPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-box-multiple';

    protected static ?string $slug = 'modpacks';

    protected static ?int $navigationSort = 31;

    private const PER_PAGE = 20;

    /** Versions listed in the detail view (newest first). */
    private const MAX_VERSIONS = 25;

    private const MAX_BODY_CHARS = 20000;

    private ?PackInstall $latestInstall = null;

    private bool $latestInstallLoaded = false;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && parent::canAccess()
            && ServerTarget::isMinecraft($server)
            && static::userCan(SubuserPermission::FileRead);
    }

    /** Installing or cancelling: the install reinstalls pack files, writes, deletes (moves) files and makes a backup. */
    public static function canInstall(): bool
    {
        return static::userCan(SubuserPermission::SettingsReinstall, SubuserPermission::FileCreate, SubuserPermission::FileDelete, SubuserPermission::BackupCreate);
    }

    /** Whether the signed-in user holds every permission on the current server (owners and admins pass through User::can()). */
    protected static function userCan(SubuserPermission ...$permissions): bool
    {
        $user = user();
        $server = Filament::getTenant();

        if (!$user || !$server instanceof Server) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    public static function getNavigationLabel(): string
    {
        return trans('modpacks-plus::strings.page.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function server(): Server
    {
        $server = Filament::getTenant();
        abort_unless($server instanceof Server, 404);

        return $server;
    }

    private function provider(): ModrinthPackProvider
    {
        /** @var ModrinthPackProvider $provider */
        $provider = app(PackProviders::class)->get(ModrinthPackProvider::ID);

        return $provider;
    }

    private function latestInstall(): ?PackInstall
    {
        if (!$this->latestInstallLoaded) {
            $this->latestInstall = PackInstall::query()->where('server_id', $this->server()->id)->latest('id')->first();
            $this->latestInstallLoaded = true;
        }

        return $this->latestInstall;
    }

    private function activeInstall(): ?PackInstall
    {
        $install = $this->latestInstall();

        return $install?->isActive() ? $install : null;
    }

    private function lastInstalledPack(): ?PackInstall
    {
        return PackInstall::query()->where('server_id', $this->server()->id)->where('status', PackInstall::STATUS_INSTALLED)->latest('id')->first();
    }

    /**
     * Starts an install. Public so the confirm buttons can call it; it is equally callable
     * by anyone holding the page, so it re-checks every permission and re-reads the version
     * from Modrinth instead of trusting anything sent by the browser.
     */
    public function installPack(string $packId, string $versionId): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        if (!ModrinthPackProvider::isValidId($packId) || !ModrinthPackProvider::isValidId($versionId)) {
            abort(404);
        }

        $server = $this->server();
        $provider = $this->provider();

        try {
            $version = $provider->version($versionId);
            if ($version['project_id'] !== $packId) {
                abort(404);
            }

            $needs = VersionMatch::needs($version, ServerTarget::minecraftVersion($server), ServerTarget::loader($server));
            if ($needs !== null) {
                Notification::make()
                    ->title(trans('modpacks-plus::strings.install.not_matching'))
                    ->body(trans('modpacks-plus::strings.install.needs', ['target' => $needs]))
                    ->danger()
                    ->send();

                return;
            }

            $project = $provider->project($packId);
            $install = app(InstallEngine::class)->start(
                $server,
                user(),
                ModrinthPackProvider::ID,
                (string) $project['id'],
                $version['id'],
                (string) $project['title'],
                $version['version_number'],
                is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            );
        } catch (InstallRefusedException|PackUnavailableException $exception) {
            Notification::make()
                ->title(trans('modpacks-plus::strings.install.refused'))
                ->body(InstallProgress::reason($exception->reason, $exception->detail))
                ->danger()
                ->send();

            return;
        }

        $this->latestInstallLoaded = false;

        Notification::make()
            ->title(trans('modpacks-plus::strings.install.started', ['name' => $install->name]))
            ->success()
            ->send();
    }

    /** Asks the running install to stop after the current step or download batch. Same permissions as installing. */
    public function cancelInstall(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        $install = PackInstall::query()->where('server_id', $this->server()->id)->active()->latest('id')->first();
        if ($install === null) {
            return;
        }

        app(InstallEngine::class)->requestCancel($install);
        $this->latestInstallLoaded = false;

        Notification::make()
            ->title(trans('modpacks-plus::strings.install.cancel_requested'))
            ->warning()
            ->send();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<int, string>
     */
    private function selectedCategories(?array $filters): array
    {
        $values = is_array($filters['categories'] ?? null) ? ($filters['categories']['values'] ?? null) : null;
        $known = $this->provider()->categories();

        return is_array($values) ? array_values(array_intersect(array_filter($values, 'is_string'), $known)) : [];
    }

    /** @param  array<string, mixed>|null  $filters */
    private function selectedSort(?array $filters): ?string
    {
        $value = is_array($filters['sort'] ?? null) ? ($filters['sort']['value'] ?? null) : null;

        return is_string($value) && in_array($value, ModrinthPackProvider::SORTS, true) ? $value : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, ?array $filters) {
                $server = $this->server();

                try {
                    $result = $this->provider()->search(
                        $search,
                        ServerTarget::minecraftVersion($server),
                        ServerTarget::loader($server),
                        $this->selectedCategories($filters),
                        $this->selectedSort($filters),
                        $page,
                        self::PER_PAGE,
                    );
                } catch (PackUnavailableException $exception) {
                    report($exception);
                    Notification::make()->title(InstallProgress::reason($exception->reason, $exception->detail))->danger()->send();

                    return new LengthAwarePaginator([], 0, self::PER_PAGE, $page);
                }

                $records = [];
                foreach ($result['hits'] as $hit) {
                    $records[$hit['id']] = $hit;
                }

                return new LengthAwarePaginator($records, $result['total'], self::PER_PAGE, $page);
            })
            ->deferLoading()
            ->paginated([self::PER_PAGE])
            ->filters([
                SelectFilter::make('sort')
                    ->label(trans('modpacks-plus::strings.filters.sort'))
                    ->options(collect(ModrinthPackProvider::SORTS)->mapWithKeys(fn (string $sort) => [$sort => trans('modpacks-plus::strings.filters.sorts.'.$sort)])->all())
                    ->placeholder(trans('modpacks-plus::strings.filters.sorts.relevance')),
                SelectFilter::make('categories')
                    ->label(trans('modpacks-plus::strings.filters.categories'))
                    ->multiple()
                    ->options(fn () => collect($this->provider()->categories())->mapWithKeys(fn (string $category) => [$category => Str::headline($category)])->all()),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->columns([
                ImageColumn::make('icon_url')->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (array $record) => Str::limit((string) $record['description'], 120)),
                TextColumn::make('author')->toggleable(),
                TextColumn::make('downloads')->icon('tabler-download')->numeric()->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => is_string($state) ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(trans('modpacks-plus::strings.actions.details'))
                    ->icon('tabler-list-details')
                    ->color('info')
                    ->slideOver()
                    ->modalHeading(fn (array $record) => (string) $record['title'])
                    ->modalSubmitAction(false)
                    ->schema(fn (array $record) => $this->detailSchema((string) $record['id'])),
            ]);
    }

    /** @return array<int, Component> */
    private function detailSchema(string $packId): array
    {
        try {
            $project = $this->provider()->project($packId);
            $versions = array_slice($this->provider()->versions($packId), 0, self::MAX_VERSIONS);
        } catch (PackUnavailableException $exception) {
            return [Html::make(new HtmlString('<p>'.e(InstallProgress::reason($exception->reason, $exception->detail)).'</p>'))];
        }

        $server = $this->server();
        $serverMinecraft = ServerTarget::minecraftVersion($server);
        $serverLoader = ServerTarget::loader($server);

        $components = [
            Html::make($this->markdown((string) $project['body'] !== '' ? (string) $project['body'] : (string) $project['description'])),
        ];

        if ($project['gallery'] !== []) {
            $images = '';
            foreach (array_slice($project['gallery'], 0, 8) as $image) {
                $images .= '<img src="'.e($image['url']).'" alt="'.e((string) ($image['title'] ?? '')).'" loading="lazy" referrerpolicy="no-referrer" style="max-width: 100%; border-radius: 0.5rem; margin-bottom: 0.5rem">';
            }
            $components[] = Section::make(trans('modpacks-plus::strings.details.gallery'))->collapsible()->collapsed()->schema([Html::make(new HtmlString($images))]);
        }

        $sections = [];
        foreach ($versions as $index => $version) {
            $needs = VersionMatch::needs($version, $serverMinecraft, $serverLoader);

            $action = $needs === null
                ? $this->makeInstallAction($index, $packId, $version, (string) $project['title'])
                : Action::make('needs_'.$index)
                    ->label(trans('modpacks-plus::strings.install.needs', ['target' => $needs]))
                    ->icon('tabler-lock')
                    ->color('gray')
                    ->disabled();

            $sections[] = Section::make($version['version_number'] !== '' ? $version['version_number'] : $version['name'])
                ->description(implode(', ', $version['loaders']).' · '.implode(', ', array_slice($version['game_versions'], 0, 6)))
                ->headerActions([$action])
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('published_'.$index)
                        ->label(trans('modpacks-plus::strings.details.published'))
                        ->state($version['date_published'] ? Carbon::parse($version['date_published'], 'UTC')->diffForHumans() : ''),
                    Html::make($this->markdown((string) ($version['changelog'] ?? ''))),
                ]);
        }

        $components[] = Section::make(trans('modpacks-plus::strings.details.versions'))->schema($sections === [] ? [Html::make(new HtmlString('<p>'.e(trans('modpacks-plus::strings.details.no_versions')).'</p>'))] : $sections);

        return $components;
    }

    /** @param  array{id: string, version_number: string, name: string}  $version */
    private function makeInstallAction(int $index, string $packId, array $version, string $title): Action
    {
        $versionId = $version['id'];

        return Action::make('install_'.$index)
            ->label(trans('modpacks-plus::strings.actions.install'))
            ->icon('tabler-download')
            ->color('success')
            ->authorize(fn () => static::canInstall())
            ->disabled(fn () => $this->activeInstall() !== null)
            ->requiresConfirmation()
            ->modalHeading(trans('modpacks-plus::strings.install.confirm_heading', ['name' => $title, 'version' => $version['version_number']]))
            ->modalDescription(trans('modpacks-plus::strings.install.confirm_body', ['folders' => 'mods/, config/, defaultconfigs/, kubejs/, scripts/']))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.actions.install'))
            ->action(fn () => $this->installPack($packId, $versionId));
    }

    /** Markdown from Modrinth, with raw HTML stripped and unsafe links dropped. */
    private function markdown(string $markdown): HtmlString
    {
        if (trim($markdown) === '') {
            return new HtmlString('');
        }

        $html = Str::markdown(Str::limit($markdown, self::MAX_BODY_CHARS), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return new HtmlString('<div class="fi-prose">'.$html.'</div>');
    }

    public function content(Schema $schema): Schema
    {
        $server = $this->server();

        return $schema->components([
            Callout::make(trans('modpacks-plus::strings.page.unsupported'))
                ->description(trans('modpacks-plus::strings.reasons.server_not_supported'))
                ->icon('tabler-alert-triangle')
                ->color('warning')
                ->visible(fn () => ServerTarget::minecraftVersion($server) === null || ServerTarget::loader($server) === null),
            Callout::make(fn () => trans('modpacks-plus::strings.page.installed_pack', [
                'name' => (string) $this->lastInstalledPack()?->name,
                'version' => (string) $this->lastInstalledPack()?->version_number,
            ]))
                ->description(fn () => $this->lastInstalledPack()?->finished_at?->diffForHumans())
                ->icon('tabler-package')
                ->color('success')
                ->visible(fn () => $this->lastInstalledPack() !== null),
            Section::make(fn () => trans('modpacks-plus::strings.page.progress', [
                'name' => $this->latestInstall()->name ?? '',
                'status' => trans('modpacks-plus::strings.status.'.($this->latestInstall()->status ?? 'installing')),
            ]))
                ->key('install-progress')
                ->poll(fn () => $this->activeInstall() !== null ? '3s' : null)
                ->visible(fn () => $this->latestInstall() !== null)
                ->collapsible()
                ->collapsed(fn () => $this->activeInstall() === null)
                ->headerActions([
                    Action::make('cancel_install')
                        ->label(trans('modpacks-plus::strings.actions.cancel'))
                        ->icon('tabler-player-stop')
                        ->color('danger')
                        ->authorize(fn () => static::canInstall())
                        ->visible(fn () => $this->activeInstall() !== null && $this->activeInstall()->cancel_requested_at === null)
                        ->requiresConfirmation()
                        ->modalDescription(trans('modpacks-plus::strings.install.cancel_body'))
                        ->action(fn () => $this->cancelInstall()),
                ])
                ->schema([
                    Callout::make(fn () => trans('modpacks-plus::strings.status.failed'))
                        ->description(fn () => ($install = $this->latestInstall()) ? InstallProgress::error($install) : null)
                        ->color('danger')
                        ->visible(fn () => $this->latestInstall()?->error !== null),
                    Grid::make(2)->schema([
                        Html::make(fn () => ($install = $this->latestInstall()) ? InstallProgress::steps($install) : null),
                        Html::make(fn () => ($install = $this->latestInstall()) && !$install->isActive() ? InstallProgress::summary($install) : null),
                    ]),
                    Html::make(fn () => ($install = $this->latestInstall()) ? InstallProgress::log($install, user()->timezone ?? 'UTC') : null),
                ]),
            Grid::make(2)->schema([
                TextEntry::make('minecraft_version')
                    ->label(trans('modpacks-plus::strings.page.minecraft_version'))
                    ->state(fn () => ServerTarget::minecraftVersion($server) ?? trans('modpacks-plus::strings.page.unknown'))
                    ->badge(),
                TextEntry::make('loader')
                    ->label(trans('modpacks-plus::strings.page.loader'))
                    ->state(fn () => ($loader = ServerTarget::loader($server)) !== null ? Str::headline($loader) : trans('modpacks-plus::strings.page.unknown'))
                    ->badge(),
            ]),
            EmbeddedTable::make(),
        ]);
    }
}
