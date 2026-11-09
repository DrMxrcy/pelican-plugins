<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Pages;

use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Models\Egg;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\JavaImagePicker;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Browse Modrinth modpacks and install one onto this server, switching the server's
 * loader egg first when the pack needs another loader or Minecraft version.
 *
 * Permissions: viewing needs file.read; installing, cancelling and restoring need
 * settings.reinstall + file.create + file.delete + backup.create. Switching the loader
 * (an egg change) additionally needs an admin ("update server" on this server), unless
 * the plugin setting "Let server users switch loaders" is on. The public Livewire
 * methods (installPack, switchLoaderAndInstall, cancelInstall, restorePreviousFiles)
 * check them themselves: they are callable from the browser no matter which buttons are shown.
 */
class ModpacksPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-box-multiple';

    protected static ?string $slug = 'modpacks';

    protected static ?int $navigationSort = 31;

    private const PER_PAGE = 20;

    /** Versions listed in the detail view (newest first). */
    private const MAX_VERSIONS = 25;

    private const MAX_BODY_CHARS = 20000;

    private ?PackInstall $latestInstall = null;

    /** @var array<string, array{egg: Egg, image: array{label: string, image: string, java: int}}|InstallRefusedException> per loader|minecraft, this request */
    private array $switchTargets = [];

    private bool $latestInstallLoaded = false;

    /**
     * Deliberately reachable while the server is in a conflict state (installing, a failed
     * (re)install, …): that is when the install progress and "Restore previous files" are
     * needed. Every step and the restore job check the server's state themselves.
     */
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

    /**
     * Switching the loader changes the server's egg and reinstalls it, which Pelican only
     * lets admins do: admins with "update server" on this server (root admins included),
     * or, when the plugin setting allows it, anyone holding the install permissions.
     */
    public static function canSwitchLoader(): bool
    {
        $user = user();
        $server = Filament::getTenant();
        if (!$user || !$server instanceof Server || !static::canInstall()) {
            return false;
        }

        return $user->can('update', $server) || (bool) config('modpacks-plus.allow_user_loader_switch', false);
    }

    /**
     * The loader and Minecraft version a pack version would switch the server to (keeping
     * whichever of the two already matches), or null when it has no supported loader.
     *
     * @param  array{game_versions: array<int, string>, loaders: array<int, string>}  $version
     * @return array{loader: string, minecraft: string}|null
     */
    public static function switchTarget(array $version, ?string $serverMinecraft, ?string $serverLoader): ?array
    {
        $loaders = array_values(array_intersect(array_map('strtolower', $version['loaders']), ServerTarget::LOADERS));
        if ($loaders === []) {
            return null;
        }

        $loader = $serverLoader !== null && in_array($serverLoader, $loaders, true) ? $serverLoader : $loaders[0];
        $minecraft = $serverMinecraft !== null && in_array($serverMinecraft, $version['game_versions'], true)
            ? $serverMinecraft
            : ($version['game_versions'][0] ?? '');

        return $minecraft !== '' ? ['loader' => $loader, 'minecraft' => $minecraft] : null;
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

    /**
     * Switches the server to the loader egg a pack version needs, then installs it. Public
     * so the confirm button can call it; it re-checks the switch permission and re-reads
     * the version from Modrinth, and picks the egg on the server side. With no usable egg
     * nothing is created or changed and the message names the egg to import.
     */
    public function switchLoaderAndInstall(string $packId, string $versionId): void
    {
        abort_unless(static::canAccess() && static::canInstall() && static::canSwitchLoader(), 403);

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

            if (VersionMatch::needs($version, ServerTarget::minecraftVersion($server), ServerTarget::loader($server)) === null) {
                $this->installPack($packId, $versionId);

                return;
            }

            $target = self::switchTarget($version, ServerTarget::minecraftVersion($server), ServerTarget::loader($server));
            if ($target === null) {
                throw new InstallRefusedException('loader_unsupported', implode(', ', $version['loaders']));
            }

            ['egg' => $egg] = $this->switchEgg($target);

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
                ['loader' => $target['loader'], 'minecraft' => $target['minecraft'], 'egg_id' => $egg->id, 'egg_name' => $egg->name],
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

    /**
     * The egg and Java image a switch to $target uses (resolved once per request).
     *
     * @param  array{loader: string, minecraft: string}  $target
     * @return array{egg: Egg, image: array{label: string, image: string, java: int}}
     *
     * @throws InstallRefusedException
     */
    private function switchEgg(array $target): array
    {
        $key = $target['loader'].'|'.$target['minecraft'];
        if (!array_key_exists($key, $this->switchTargets)) {
            try {
                $egg = (new LoaderEggResolver())->resolve($target['loader']);
                $this->switchTargets[$key] = ['egg' => $egg, 'image' => (new JavaImagePicker())->pick($egg->docker_images ?? [], $target['minecraft'])];
            } catch (InstallRefusedException $exception) {
                $this->switchTargets[$key] = $exception;
            }
        }

        $resolved = $this->switchTargets[$key];
        if ($resolved instanceof InstallRefusedException) {
            throw $resolved;
        }

        return $resolved;
    }

    /**
     * The confirm text of a switch: exactly what resets (startup command, Docker image,
     * variables whose names differ, mounts the new egg doesn't allow), what is kept, and
     * that the server reinstalls. Or why it can't be done.
     *
     * @param  array{loader: string, minecraft: string}  $target
     */
    private function switchDescription(array $target): HtmlString
    {
        try {
            ['egg' => $egg, 'image' => $image] = $this->switchEgg($target);
        } catch (InstallRefusedException $exception) {
            return new HtmlString('<p>'.e(InstallProgress::reason($exception->reason, $exception->detail)).'</p>');
        }

        $server = $this->server();
        $server->loadMissing('egg');
        $sameEgg = $server->egg_id === $egg->id;
        $versionVariables = EggVariableMap::requiredVariables($target['loader']);

        // The startup command and image are only shown to those who may read the startup settings.
        $showSetup = static::userCan(SubuserPermission::StartupRead);

        $resets = [];
        if (!$sameEgg) {
            $resets[] = $showSetup
                ? trans('modpacks-plus::strings.install.reset_startup', ['from' => (string) $server->startup, 'to' => (string) Arr::first($egg->startup_commands ?? [])])
                : trans('modpacks-plus::strings.install.reset_startup_hidden');
        }
        $resets[] = $showSetup
            ? trans('modpacks-plus::strings.install.reset_image', ['from' => (string) $server->image, 'to' => $image['image']])
            : trans('modpacks-plus::strings.install.reset_image_hidden');
        $resets[] = trans('modpacks-plus::strings.install.reset_versions', ['variables' => implode(', ', $versionVariables)]);

        if (!$sameEgg) {
            $current = $server->variables()->pluck('env_variable')->all();
            $new = $egg->variables()->pluck('env_variable')->all();
            $dropped = array_values(array_diff($current, $new));
            $defaults = array_values(array_diff($new, $current, $versionVariables));
            if ($dropped !== []) {
                $resets[] = trans('modpacks-plus::strings.install.reset_variables_dropped', ['variables' => implode(', ', $dropped)]);
            }
            if ($defaults !== []) {
                $resets[] = trans('modpacks-plus::strings.install.reset_variables_default', ['variables' => implode(', ', $defaults)]);
            }

            $mounts = $server->mounts()
                ->whereHas('eggs')
                ->whereDoesntHave('eggs', fn (Builder $query) => $query->where('eggs.id', $egg->id))
                ->pluck('name')
                ->all();
            if ($mounts !== []) {
                $resets[] = trans('modpacks-plus::strings.install.reset_mounts', ['mounts' => implode(', ', $mounts)]);
            }
        }

        $intro = trans('modpacks-plus::strings.install.switch_intro', [
            'from' => $server->egg->name,
            'to' => $egg->name,
            'target' => ServerTarget::loaderName($target['loader']).' '.$target['minecraft'],
        ]);

        return new HtmlString(
            '<p>'.e($intro).'</p>'
            .'<ul style="list-style: disc; padding-left: 1.25rem; margin: 0.5rem 0">'.implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $resets)).'</ul>'
            .'<p>'.e(trans('modpacks-plus::strings.install.switch_kept')).'</p>'
        );
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
     * "Restore previous files" for this server's latest install when it ended failed or
     * cancelled: queues RestorePreviousFilesJob, which moves the set-aside worlds, player
     * files and old pack folders back (never overwriting). Same permissions as installing;
     * which install and which files come from the database, never from the browser.
     */
    public function restorePreviousFiles(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        $server = $this->server();
        $install = PackInstall::restoreCandidate($server->id);
        if ($install === null || $install->isRestoring()) {
            Notification::make()->title(trans('modpacks-plus::strings.restore.nothing'))->warning()->send();

            return;
        }

        // Never while an install script may still be running on the node.
        if ($server->status === ServerState::Installing || RestorePreviousFilesJob::reinstallMayBeRunning($install, $server)) {
            Notification::make()
                ->title(trans('modpacks-plus::strings.restore.pending'))
                ->body(trans('modpacks-plus::strings.restore.pending_body', ['minutes' => RestorePreviousFilesJob::REINSTALL_QUIET_MINUTES]))
                ->warning()
                ->send();

            return;
        }

        if (config('queue.default') === 'sync') {
            Notification::make()
                ->title(trans('modpacks-plus::strings.install.refused'))
                ->body(InstallProgress::reason('queue_required', ''))
                ->danger()
                ->send();

            return;
        }

        // Claimed atomically: two clicks (or two tabs) start one restore.
        $claimed = PackInstall::query()
            ->whereKey($install->id)
            ->whereNull('restored_at')
            ->where(fn ($query) => $query->whereNull('restore_requested_at')->orWhere('restore_requested_at', '<', now()->subMinutes(PackInstall::RESTORE_MINUTES)))
            ->update(['restore_requested_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        dispatch(new RestorePreviousFilesJob($install->id));
        $this->latestInstallLoaded = false;

        Notification::make()->title(trans('modpacks-plus::strings.restore.started'))->success()->send();
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
                : $this->makeMismatchAction($index, $packId, $version, (string) $project['title'], $needs, self::switchTarget($version, $serverMinecraft, $serverLoader));

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
            // Hidden without the install permissions; installPack() re-checks them anyway.
            ->visible(fn () => static::canInstall())
            ->authorize(fn () => static::canInstall())
            ->disabled(fn () => $this->activeInstall() !== null)
            ->requiresConfirmation()
            ->modalHeading(trans('modpacks-plus::strings.install.confirm_heading', ['name' => $title, 'version' => $version['version_number']]))
            ->modalDescription(trans('modpacks-plus::strings.install.confirm_body', ['folders' => 'mods/, config/, defaultconfigs/, kubejs/, scripts/']))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.actions.install'))
            ->action(fn () => $this->installPack($packId, $versionId));
    }

    /**
     * A version for another loader or Minecraft version: "Switch to … and install" for
     * those allowed to switch, "ask an admin" for those who may install but not switch,
     * otherwise just what it needs.
     *
     * @param  array{id: string, version_number: string, name: string}  $version
     * @param  array{loader: string, minecraft: string}|null  $target
     */
    private function makeMismatchAction(int $index, string $packId, array $version, string $title, string $needs, ?array $target): Action
    {
        if ($target === null || !static::canInstall()) {
            return Action::make('needs_'.$index)
                ->label(trans('modpacks-plus::strings.install.needs', ['target' => $needs]))
                ->icon('tabler-lock')
                ->color('gray')
                ->disabled();
        }

        if (!static::canSwitchLoader()) {
            return Action::make('needs_'.$index)
                ->label(trans('modpacks-plus::strings.install.ask_admin', ['target' => $needs]))
                ->icon('tabler-lock')
                ->color('gray')
                ->disabled();
        }

        $versionId = $version['id'];

        return Action::make('switch_'.$index)
            ->label(trans('modpacks-plus::strings.install.switch', ['target' => $needs]))
            ->icon('tabler-switch-horizontal')
            ->color('warning')
            // Hidden without the switch permission; switchLoaderAndInstall() re-checks it anyway.
            ->visible(fn () => static::canSwitchLoader())
            ->authorize(fn () => static::canSwitchLoader())
            ->disabled(fn () => $this->activeInstall() !== null)
            ->requiresConfirmation()
            ->modalHeading(trans('modpacks-plus::strings.install.switch_heading', ['name' => $title, 'version' => $version['version_number'], 'target' => $needs]))
            ->modalDescription(fn () => $this->switchDescription($target))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.install.switch_confirm'))
            ->action(fn () => $this->switchLoaderAndInstall($packId, $versionId));
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
                ->poll(fn () => $this->activeInstall() !== null || $this->latestInstall()?->isRestoring() ? '3s' : null)
                ->visible(fn () => $this->latestInstall() !== null)
                ->collapsible()
                ->collapsed(fn () => $this->activeInstall() === null)
                ->headerActions([
                    Action::make('cancel_install')
                        ->label(trans('modpacks-plus::strings.actions.cancel'))
                        ->icon('tabler-player-stop')
                        ->color('danger')
                        ->authorize(fn () => static::canInstall())
                        ->visible(fn () => static::canInstall() && $this->activeInstall() !== null && $this->activeInstall()->cancel_requested_at === null)
                        ->requiresConfirmation()
                        ->modalDescription(trans('modpacks-plus::strings.install.cancel_body'))
                        ->action(fn () => $this->cancelInstall()),
                    Action::make('restore_previous')
                        ->label(trans('modpacks-plus::strings.actions.restore'))
                        ->icon('tabler-restore')
                        ->color('warning')
                        ->authorize(fn () => static::canInstall())
                        ->visible(fn () => static::canInstall() && ($candidate = PackInstall::restoreCandidate($server->id)) !== null && !$candidate->isRestoring())
                        ->requiresConfirmation()
                        ->modalHeading(trans('modpacks-plus::strings.restore.heading'))
                        ->modalDescription(trans('modpacks-plus::strings.restore.body'))
                        ->modalSubmitActionLabel(trans('modpacks-plus::strings.actions.restore'))
                        ->action(fn () => $this->restorePreviousFiles()),
                ])
                ->schema([
                    Callout::make(fn () => trans('modpacks-plus::strings.status.failed'))
                        ->description(fn () => ($install = $this->latestInstall()) ? InstallProgress::error($install) : null)
                        ->color('danger')
                        ->visible(fn () => $this->latestInstall()?->error !== null),
                    Grid::make(2)->schema([
                        Html::make(fn () => ($install = $this->latestInstall()) ? InstallProgress::steps($install) : null),
                        Html::make(fn () => ($install = $this->latestInstall()) && !$install->isActive() ? InstallProgress::summary($install, static::userCan(SubuserPermission::StartupRead)) : null),
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
