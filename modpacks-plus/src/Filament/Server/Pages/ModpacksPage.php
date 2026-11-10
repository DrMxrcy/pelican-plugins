<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Pages;

use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Models\Egg;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackLink;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\JavaImagePicker;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Services\LoaderVersions;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Services\PackSizes;
use DrMxrcy\ModpacksPlus\Services\ServerDiskUsage;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\DiskBudget;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use DrMxrcy\ModpacksPlus\Support\ServerAvailability;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use DrMxrcy\ModpacksPlus\Support\VersionMatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Browse Modrinth, CurseForge, FTB and ATLauncher modpacks and install one onto this server, switching the server's
 * loader egg first when the pack needs another loader or Minecraft version.
 *
 * Permissions: viewing needs file.read; installing, cancelling and restoring need
 * settings.reinstall + file.create + file.delete + backup.create. Switching the loader
 * (an egg change) additionally needs an admin ("update server" on this server), unless
 * the plugin setting "Let server users switch loaders" is on; so does the "Minecraft version"
 * picker (plan 40), which is a switch without a pack. The public Livewire methods
 * (installPack, switchLoaderAndInstall, changeMinecraftVersion, cancelInstall,
 * restorePreviousFiles) check them themselves: they are callable from the browser no matter
 * which buttons are shown.
 *
 * The source (Modrinth, CurseForge, FTB or ATLauncher) is a public property the browser can set to anything:
 * it is only ever used through source(), which falls back to Modrinth for unknown values.
 * CurseForge without an API key or the opt-in mirror makes no API call at all.
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

    /** Days the page keeps pointing at the files of a discarded restore, whatever runs after it. */
    private const DISCARDED_BANNER_DAYS = 7;

    private ?PackInstall $latestInstall = null;

    /** @var array<string, array{egg: Egg, image: array{label: string, image: string, java: int}}|InstallRefusedException> per loader|minecraft, this request */
    private array $switchTargets = [];

    private bool $latestInstallLoaded = false;

    /** @var array<string, string>|null plan 40: loader => label of the loaders whose official egg resolves, this request */
    private ?array $versionLoaders = null;

    private ?LoaderVersions $loaderVersions = null;

    /** Plan 38: the disk budget, read lazily (confirm modal / install button), once per request. */
    private ?DiskBudget $diskBudget = null;

    /** Seconds the node's disk usage is reused between requests of the page. */
    private const DISK_USAGE_CACHE_SECONDS = 15;

    /** Timeout for the node's disk usage call from the page. */
    private const DISK_USAGE_TIMEOUT = 2;

    /** Which provider the table lists (PackProviders::IDS); read through source(). */
    public string $source = ModrinthPackProvider::ID;

    /** Why the current source could not be read in this request (shown as the table's empty state). */
    private ?string $sourceError = null;

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

    /** The selected source, never anything but a known provider id. */
    private function source(): string
    {
        return in_array($this->source, PackProviders::IDS, true) ? $this->source : ModrinthPackProvider::ID;
    }

    private function provider(?string $source = null): PackProvider
    {
        return app(PackProviders::class)->get($source ?? $this->source());
    }

    private function modrinth(): ModrinthPackProvider
    {
        /** @var ModrinthPackProvider $provider */
        $provider = app(PackProviders::class)->get(ModrinthPackProvider::ID);

        return $provider;
    }

    /** Whether the source can be asked anything (CurseForge needs a key or the mirror). */
    private function sourceReady(?string $source = null): bool
    {
        return ($source ?? $this->source()) !== CurseForgePackProvider::ID || CurseForgeClient::fromConfig()->configured();
    }

    /** Switches the listed source. Unknown values are ignored. */
    public function setSource(string $source): void
    {
        if (!in_array($source, PackProviders::IDS, true)) {
            return;
        }

        $this->source = $source;
        $this->sourceError = null;
        $this->resetTable();
    }

    /** The "Also on Modrinth" button: lists the Modrinth pack with that title (the user installs from there). */
    public function showOnModrinth(string $title): void
    {
        $this->setSource(ModrinthPackProvider::ID);
        $this->tableSearch = mb_substr(trim($title), 0, 100);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [...array_map(fn (string $source) => Action::make('source_'.$source)
            ->label(trans('modpacks-plus::strings.page.sources.'.$source))
            ->icon(match ($source) {
                ModrinthPackProvider::ID => 'tabler-box',
                CurseForgePackProvider::ID => 'tabler-flame',
                FtbPackProvider::ID => 'tabler-hammer',
                default => 'tabler-rocket',
            })
            ->color(fn () => $this->source() === $source ? 'primary' : 'gray')
            ->action(fn () => $this->setSource($source)), PackProviders::IDS), $this->openCurseForgeLinkAction(), $this->minecraftVersionAction()];
    }

    /**
     * Plan 40: "Minecraft version" picks the loader (only those whose official egg is imported,
     * LoaderEggResolver), the Minecraft version and the loader version (default: the latest
     * stable build) from the loaders' own lists, then switches egg and version variables and
     * reinstalls (InstallEngine::startVersionChange). The lists are only fetched once the form
     * is open (the options below), never for the page itself. Who may: as for a loader switch.
     */
    private function minecraftVersionAction(): Action
    {
        return Action::make('minecraft_version')
            ->label(trans('modpacks-plus::strings.version.action'))
            ->icon('tabler-versions')
            ->color('gray')
            // Hidden without the switch permission; changeMinecraftVersion() re-checks it anyway.
            ->visible(fn () => static::canSwitchLoader())
            ->authorize(fn () => static::canSwitchLoader())
            // Plan 39: it reinstalls the server, so a failed install doesn't block it.
            ->disabled(fn () => $this->activeInstall() !== null || $this->serverBlocked(forSwitch: true) !== null)
            ->tooltip(fn () => $this->serverBlocked(forSwitch: true))
            ->modalHeading(trans('modpacks-plus::strings.version.heading'))
            ->modalDescription(trans('modpacks-plus::strings.version.description'))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.version.submit'))
            ->schema([
                Select::make('loader')
                    ->label(trans('modpacks-plus::strings.version.loader'))
                    ->options(fn () => $this->versionLoaders())
                    ->default(fn () => ($current = ServerTarget::loader($this->server())) !== null && isset($this->versionLoaders()[$current]) ? $current : null)
                    ->helperText(fn () => $this->versionLoaders() === [] ? trans('modpacks-plus::strings.version.no_loaders') : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('minecraft', null);
                        $set('loader_version', null);
                    }),
                Select::make('minecraft')
                    ->label(trans('modpacks-plus::strings.version.minecraft'))
                    ->options(fn (Get $get) => $this->minecraftOptions($get('loader')))
                    ->helperText(fn (Get $get) => $this->versionsError($get('loader')) ?? trans('modpacks-plus::strings.version.releases_only'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => $set('loader_version', array_key_first($this->loaderVersionOptions($get('loader'), $get('minecraft'))))),
                Select::make('loader_version')
                    ->label(trans('modpacks-plus::strings.version.loader_version'))
                    ->options(fn (Get $get) => $this->loaderVersionOptions($get('loader'), $get('minecraft')))
                    ->helperText(trans('modpacks-plus::strings.version.loader_version_help'))
                    ->visible(fn (Get $get) => is_string($get('loader')) && isset($this->versionLoaders()[$get('loader')]) && EggVariableMap::pinsLoaderVersion($get('loader'))),
                Html::make(fn (Get $get) => $this->versionChangeNotes($get('loader'), $get('minecraft'))),
            ])
            ->action(fn (array $data) => $this->changeMinecraftVersion(
                is_string($data['loader'] ?? null) ? $data['loader'] : '',
                is_string($data['minecraft'] ?? null) ? $data['minecraft'] : '',
                is_string($data['loader_version'] ?? null) ? $data['loader_version'] : null,
            ));
    }

    /**
     * The loaders the picker offers: those whose official egg resolves (no request leaves the panel).
     *
     * @return array<string, string>
     */
    private function versionLoaders(): array
    {
        if ($this->versionLoaders === null) {
            $resolver = new LoaderEggResolver();
            $this->versionLoaders = [];
            foreach (EggVariableMap::loaders() as $loader) {
                try {
                    $resolver->resolve($loader);
                    $this->versionLoaders[$loader] = ServerTarget::loaderName($loader);
                } catch (InstallRefusedException) {
                    // No usable egg: not offered (as the switch offer does).
                }
            }
        }

        return $this->versionLoaders;
    }

    private function loaderVersions(): LoaderVersions
    {
        return $this->loaderVersions ??= app(LoaderVersions::class);
    }

    /**
     * The lists of an offered loader, or null (not offered, or the lists are unavailable).
     *
     * @return array<string, array<int, string>>|null
     */
    private function versionLists(mixed $loader): ?array
    {
        if (!is_string($loader) || !isset($this->versionLoaders()[$loader])) {
            return null;
        }

        try {
            return $this->loaderVersions()->versions($loader);
        } catch (PackUnavailableException) {
            return null;
        }
    }

    /** Why the lists of $loader can't be shown (an outage), or null. */
    private function versionsError(mixed $loader): ?string
    {
        if (!is_string($loader) || !isset($this->versionLoaders()[$loader])) {
            return null;
        }

        try {
            $this->loaderVersions()->versions($loader);
        } catch (PackUnavailableException $exception) {
            return InstallProgress::reason($exception->reason, $exception->detail);
        }

        return null;
    }

    /** @return array<string, string> */
    private function minecraftOptions(mixed $loader): array
    {
        $versions = array_keys($this->versionLists($loader) ?? []);

        return array_combine($versions, $versions);
    }

    /** @return array<string, string> newest (the default) first */
    private function loaderVersionOptions(mixed $loader, mixed $minecraft): array
    {
        if (!is_string($loader) || !is_string($minecraft) || !EggVariableMap::pinsLoaderVersion($loader)) {
            return [];
        }

        $versions = ($this->versionLists($loader) ?? [])[$minecraft] ?? [];

        return array_combine($versions, $versions);
    }

    /** What the chosen change does: the egg change and its resets, or what stays for the same loader. */
    private function versionChangeNotes(mixed $loader, mixed $minecraft): ?HtmlString
    {
        if (!is_string($loader) || !is_string($minecraft) || !isset($this->minecraftOptions($loader)[$minecraft])) {
            return null;
        }

        $server = $this->server();
        $currentLoader = ServerTarget::loader($server);
        $currentMinecraft = ServerTarget::minecraftVersion($server);

        $lines = [];
        if ($currentMinecraft !== null && preg_match('/^\d+(\.\d+)*$/', $currentMinecraft) && version_compare($minecraft, $currentMinecraft, '<')) {
            $lines[] = '<p><strong>'.e(trans('modpacks-plus::strings.version.downgrade', ['current' => $currentMinecraft])).'</strong></p>';
        }

        if ($currentLoader !== $loader) {
            return new HtmlString(implode('', $lines).$this->switchDescription(['loader' => $loader, 'minecraft' => $minecraft])->toHtml());
        }

        $lines[] = '<p>'.e(trans('modpacks-plus::strings.version.same_loader', [
            'from' => ServerTarget::loaderName($loader).' '.($currentMinecraft ?? '?'),
            'to' => ServerTarget::loaderName($loader).' '.$minecraft,
        ])).'</p>';
        $lines[] = '<p>'.e(trans('modpacks-plus::strings.install.reset_versions', ['variables' => implode(', ', EggVariableMap::requiredVariables($loader))])).'</p>';

        return new HtmlString(implode('', $lines));
    }

    /**
     * Starts a version change (plan 40). Public so the picker can call it; it is equally callable
     * by anyone holding the page, so it re-checks the switch permission and checks the choice
     * against the loaders' lists read on the server (never trusting the browser): only the chosen
     * egg's version variables are ever set, from list values. An empty loader version is the
     * newest stable one on the list.
     */
    public function changeMinecraftVersion(string $loader, string $minecraft, ?string $loaderVersion = null): void
    {
        abort_unless(static::canAccess() && static::canInstall() && static::canSwitchLoader(), 403);
        abort_unless(in_array($loader, EggVariableMap::loaders(), true), 404);

        $server = $this->server();
        $target = ServerTarget::loaderName($loader).' '.mb_substr($minecraft, 0, 32);

        try {
            $versions = $this->loaderVersions()->versions($loader);
            if (!array_key_exists($minecraft, $versions)) {
                throw new InstallRefusedException('version_not_offered', $target);
            }

            if (EggVariableMap::pinsLoaderVersion($loader)) {
                $loaderVersion = $loaderVersion === null || $loaderVersion === '' ? ($versions[$minecraft][0] ?? null) : $loaderVersion;
                if ($loaderVersion === null || !in_array($loaderVersion, $versions[$minecraft], true)) {
                    throw new InstallRefusedException('version_not_offered', $target.' '.mb_substr((string) $loaderVersion, 0, 32));
                }
            } elseif ($loaderVersion !== null && $loaderVersion !== '') {
                throw new InstallRefusedException('version_not_offered', $target.' '.mb_substr($loaderVersion, 0, 32));
            } else {
                $loaderVersion = null;
            }

            // The values the egg gets, checked before anything is created.
            EggVariableMap::variables($loader, $minecraft, $loaderVersion);
            ['egg' => $egg] = $this->switchEgg(['loader' => $loader, 'minecraft' => $minecraft]);

            $install = app(InstallEngine::class)->startVersionChange($server, user(), [
                'loader' => $loader,
                'minecraft' => $minecraft,
                'loader_version' => $loaderVersion,
                'egg_id' => $egg->id,
                'egg_name' => $egg->name,
            ]);
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
            ->title(trans('modpacks-plus::strings.version.started', ['target' => $install->name]))
            ->success()
            ->send();
    }

    /**
     * Plan 41: opens a CurseForge modpack from a pasted link or Project ID (for keys CurseForge refuses
     * search for). The text is only parsed (CurseForgePackLink), never fetched; the pack opens in the
     * same details view as a search result, whose Install / Switch buttons keep their own checks.
     */
    private function openCurseForgeLinkAction(): Action
    {
        return Action::make('open_curseforge_link')
            ->label(trans('modpacks-plus::strings.page.curseforge_link'))
            ->icon('tabler-link')
            ->color('gray')
            ->visible(fn () => $this->source() === CurseForgePackProvider::ID && $this->sourceReady())
            ->modalHeading(trans('modpacks-plus::strings.page.curseforge_link_heading'))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.page.curseforge_link_submit'))
            ->schema([
                TextInput::make('link')
                    ->label(trans('modpacks-plus::strings.page.curseforge_link_field'))
                    ->helperText(trans('modpacks-plus::strings.page.curseforge_link_help'))
                    ->required()
                    ->maxLength(CurseForgePackLink::MAX_LENGTH)
                    ->autofocus(),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless(static::canAccess() && $this->sourceReady(CurseForgePackProvider::ID), 403);

                $provider = $this->provider(CurseForgePackProvider::ID);
                abort_unless($provider instanceof CurseForgePackProvider, 404);

                try {
                    $packId = $provider->packIdFromLink(is_string($data['link'] ?? null) ? $data['link'] : '');
                } catch (PackUnavailableException $exception) {
                    if (!in_array($exception->reason, ['curseforge_link_invalid', 'curseforge_link_needs_id', 'curseforge_not_found', 'not_a_modpack'], true)) {
                        report($exception);
                    }
                    Notification::make()->title(InstallProgress::reason($exception->reason, $exception->detail))->danger()->send();
                    $action->halt();

                    return;
                }

                $this->replaceMountedAction('curseforgePack', ['packId' => $packId]);
            });
    }

    /** The details view of a CurseForge pack opened by link or ID (packId is re-checked: the browser can mount this with anything). */
    public function curseforgePackAction(): Action
    {
        return Action::make('curseforgePack')
            ->visible(fn () => static::canAccess() && $this->sourceReady(CurseForgePackProvider::ID))
            ->slideOver()
            ->modalHeading(function (array $arguments) {
                $packId = self::linkedPackId($arguments);
                try {
                    return $packId !== null ? (string) $this->provider(CurseForgePackProvider::ID)->project($packId)['title'] : '';
                } catch (PackUnavailableException) {
                    return '';
                }
            })
            ->modalSubmitAction(false)
            ->schema(function (array $arguments) {
                $packId = self::linkedPackId($arguments);

                return $packId !== null
                    ? $this->detailSchema($packId, CurseForgePackProvider::ID)
                    : [Html::make(new HtmlString('<p>'.e(InstallProgress::reason('curseforge_link_invalid', '')).'</p>'))];
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private static function linkedPackId(array $arguments): ?string
    {
        $packId = $arguments['packId'] ?? null;

        return is_string($packId) && CurseForgePackProvider::isValidId($packId) ? $packId : null;
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

    /** The newest install of this server whose restore was discarded in the last days, if any. */
    private function recentlyDiscarded(): ?PackInstall
    {
        return PackInstall::query()->where('server_id', $this->server()->id)
            ->whereNotNull('preserved_path')
            ->where('restored_at', '>=', now()->subDays(self::DISCARDED_BANNER_DAYS))
            ->orderByDesc('id')
            ->get()
            ->first(fn (PackInstall $install) => (bool) $install->progressValue('restore_discarded'));
    }

    /** The newest installed pack; none once a version change (plan 40) came after it (its versions no longer apply). */
    private function lastInstalledPack(): ?PackInstall
    {
        $installed = PackInstall::query()->where('server_id', $this->server()->id)->where('status', PackInstall::STATUS_INSTALLED)->latest('id')->first();

        return $installed?->mode === PackInstall::MODE_VERSION ? null : $installed;
    }

    /**
     * Starts an install. Public so the confirm buttons can call it; it is equally callable
     * by anyone holding the page, so it re-checks every permission and re-reads the version
     * from Modrinth instead of trusting anything sent by the browser.
     */
    public function installPack(string $packId, string $versionId, string $source = ModrinthPackProvider::ID, bool $freshWorld = false): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        abort_unless(in_array($source, PackProviders::IDS, true), 404);
        $provider = $this->provider($source);
        if (!$provider::isValidId($packId) || !$provider::isValidId($versionId)) {
            abort(404);
        }

        $server = $this->server();

        try {
            $version = $this->packVersion($provider, $packId, $versionId);

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
                $provider->id(),
                (string) $project['id'],
                $version['id'],
                (string) $project['title'],
                $version['version_number'],
                is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
                null,
                ['fresh_world' => $freshWorld],
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
    public function switchLoaderAndInstall(string $packId, string $versionId, string $source = ModrinthPackProvider::ID, bool $freshWorld = false): void
    {
        abort_unless(static::canAccess() && static::canInstall() && static::canSwitchLoader(), 403);

        abort_unless(in_array($source, PackProviders::IDS, true), 404);
        $provider = $this->provider($source);
        if (!$provider::isValidId($packId) || !$provider::isValidId($versionId)) {
            abort(404);
        }

        $server = $this->server();

        try {
            $version = $this->packVersion($provider, $packId, $versionId);

            if (VersionMatch::needs($version, ServerTarget::minecraftVersion($server), ServerTarget::loader($server)) === null) {
                $this->installPack($packId, $versionId, $source, $freshWorld);

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
                $provider->id(),
                (string) $project['id'],
                $version['id'],
                (string) $project['title'],
                $version['version_number'],
                is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
                ['loader' => $target['loader'], 'minecraft' => $target['minecraft'], 'egg_id' => $egg->id, 'egg_name' => $egg->name],
                ['fresh_world' => $freshWorld],
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
     * Updates the installed pack to another version of the same pack (plan 36): only files that
     * changed are touched, the user's changed files are kept unless $overwriteChanges. Public so
     * the confirm buttons can call it; like installPack() it re-checks every permission, takes
     * the pack and source from the installed record (never from the browser) and re-reads the
     * version from the source. A version for another loader or Minecraft version is refused.
     */
    public function updatePack(string $versionId, bool $overwriteChanges = false): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        $installed = $this->lastInstalledPack();
        if ($installed === null || !in_array($installed->provider, PackProviders::IDS, true)) {
            Notification::make()->title(trans('modpacks-plus::strings.install.refused'))->body(InstallProgress::reason('update_no_manifest', ''))->danger()->send();

            return;
        }

        $provider = $this->provider($installed->provider);
        if (!$provider::isValidId($installed->pack_id) || !$provider::isValidId($versionId)) {
            abort(404);
        }

        $server = $this->server();

        try {
            $version = $this->packVersion($provider, $installed->pack_id, $versionId);
            if (VersionMatch::needs($version, ServerTarget::minecraftVersion($server), ServerTarget::loader($server)) !== null) {
                throw new InstallRefusedException('update_needs_switch');
            }

            $install = app(InstallEngine::class)->start(
                $server,
                user(),
                $installed->provider,
                $installed->pack_id,
                $version['id'],
                $installed->name,
                $version['version_number'],
                $installed->icon,
                null,
                ['update' => true, 'overwrite_changes' => $overwriteChanges],
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
            ->title(trans('modpacks-plus::strings.install.update_started', ['name' => $install->name, 'version' => (string) $install->version_number]))
            ->success()
            ->send();
    }

    /**
     * The pack version read again from its source; a version of another pack is a 404.
     *
     * @return array{id: string, project_id: string, name: string, version_number: string, version_type: string, game_versions: array<int, string>, loaders: array<int, string>, date_published: ?string, downloads: int, changelog: ?string, file: array{url: string, filename: string, size: int, hashes: array<string, string>}|null}
     *
     * @throws PackUnavailableException
     */
    private function packVersion(PackProvider $provider, string $packId, string $versionId): array
    {
        try {
            return $provider->packVersion($packId, $versionId);
        } catch (PackUnavailableException $exception) {
            abort_if($exception->reason === 'version_mismatch', 404);

            throw $exception;
        }
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
            // Variable names only for those who may read the startup settings; a count otherwise.
            if ($showSetup) {
                if ($dropped !== []) {
                    $resets[] = trans('modpacks-plus::strings.install.reset_variables_dropped', ['variables' => implode(', ', $dropped)]);
                }
                if ($defaults !== []) {
                    $resets[] = trans('modpacks-plus::strings.install.reset_variables_default', ['variables' => implode(', ', $defaults)]);
                }
            } elseif ($dropped !== [] || $defaults !== []) {
                $resets[] = trans('modpacks-plus::strings.install.reset_variables_count', ['count' => count($dropped) + count($defaults)]);
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

        // While an install script may still run on the node the job waits (and ends itself
        // after PackInstall::RESTORE_MINUTES); say so instead of "started".
        $waiting = $server->status === ServerState::Installing || RestorePreviousFilesJob::reinstallMayBeRunning($install, $server);

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

        // A new attempt retries everything an earlier attempt skipped, including one that was
        // cut short (a daemon error) before it could clear its own list.
        $install->refresh();
        $install->setProgress('restore_skipped', []);
        $install->save();

        dispatch(new RestorePreviousFilesJob($install->id));
        $this->latestInstallLoaded = false;

        if ($waiting) {
            Notification::make()
                ->title(trans('modpacks-plus::strings.restore.pending'))
                ->body(trans('modpacks-plus::strings.restore.pending_body', ['minutes' => RestorePreviousFilesJob::REINSTALL_QUIET_MINUTES, 'limit' => PackInstall::RESTORE_MINUTES]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title(trans('modpacks-plus::strings.restore.started'))->success()->send();
    }

    /**
     * "Discard pending restore": gives up on putting a switch's set-aside worlds and player
     * files back, so installs can run again (e.g. when the restore can't finish). Moves and
     * deletes nothing: the files stay in `.modpacks-plus/preserved-*`. Same permissions as
     * restoring, behind a confirm that says so.
     */
    public function discardPendingRestore(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);

        $install = PackInstall::restoreCandidate($this->server()->id);
        if ($install === null || !$install->hasPreservedPending() || $install->isRestoring()) {
            Notification::make()->title(trans('modpacks-plus::strings.restore.nothing'))->warning()->send();

            return;
        }

        $discarded = PackInstall::query()->whereKey($install->id)->whereNull('restored_at')
            ->where(fn ($query) => $query->whereNull('restore_requested_at')->orWhere('restore_requested_at', '<', now()->subMinutes(PackInstall::RESTORE_MINUTES)))
            ->update(['restored_at' => now()]);
        if ($discarded !== 1) {
            return;
        }

        $install->refresh();
        $install->setProgress('restore_discarded', true);
        $install->addLog('warning', 'restore_discarded', ['path' => (string) $install->preserved_path]);
        $install->save();
        $this->latestInstallLoaded = false;

        Notification::make()->title(trans('modpacks-plus::strings.restore.discarded', ['path' => (string) $install->preserved_path]))->warning()->send();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<int, string>
     */
    private function selectedCategories(?array $filters): array
    {
        $values = is_array($filters['categories'] ?? null) ? ($filters['categories']['values'] ?? null) : null;
        $known = $this->sourceReady() ? $this->provider()->categories() : [];

        return is_array($values) ? array_values(array_intersect(array_filter($values, 'is_string'), $known)) : [];
    }

    /** @param  array<string, mixed>|null  $filters */
    private function selectedSort(?array $filters): ?string
    {
        $value = is_array($filters['sort'] ?? null) ? ($filters['sort']['value'] ?? null) : null;

        return is_string($value) && in_array($value, $this->provider()->sorts(), true) ? $value : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, ?array $filters) {
                $server = $this->server();

                // CurseForge without a key or the mirror: say so, ask nothing.
                if (!$this->sourceReady()) {
                    $this->sourceError = InstallProgress::reason('curseforge_not_configured', '');

                    return new LengthAwarePaginator([], 0, self::PER_PAGE, $page);
                }

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
                    $this->sourceError = InstallProgress::reason($exception->reason, $exception->detail);
                    // Plan 41: a key refused search still opens packs by link or ID, so that is no hard error.
                    Notification::make()->title($this->sourceError)->status($exception->reason === 'curseforge_search_refused' ? 'warning' : 'danger')->send();

                    return new LengthAwarePaginator([], 0, self::PER_PAGE, $page);
                }

                $records = [];
                foreach ($result['hits'] as $hit) {
                    $records[$hit['id']] = $hit;
                }

                return new LengthAwarePaginator($records, $result['total'], self::PER_PAGE, $page);
            })
            ->deferLoading()
            ->emptyStateHeading(fn () => $this->sourceError ?? trans('modpacks-plus::strings.page.no_results'))
            ->paginated([self::PER_PAGE])
            ->filters([
                SelectFilter::make('sort')
                    ->label(trans('modpacks-plus::strings.filters.sort'))
                    ->options(fn () => collect($this->provider()->sorts())->mapWithKeys(fn (string $sort) => [$sort => trans('modpacks-plus::strings.filters.sorts.'.$sort)])->all())
                    ->placeholder(trans('modpacks-plus::strings.filters.sorts.relevance')),
                SelectFilter::make('categories')
                    ->label(trans('modpacks-plus::strings.filters.categories'))
                    ->multiple()
                    ->options(fn () => collect($this->sourceReady() ? $this->provider()->categories() : [])->mapWithKeys(fn (string $category) => [$category => Str::headline($category)])->all()),
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
    private function detailSchema(string $packId, ?string $source = null): array
    {
        $source ??= $this->source();

        try {
            $provider = $this->provider($source);
            $project = $provider->project($packId);
            $versions = array_slice($provider->versions($packId), 0, self::MAX_VERSIONS);
        } catch (PackUnavailableException $exception) {
            return [Html::make(new HtmlString('<p>'.e(InstallProgress::reason($exception->reason, $exception->detail)).'</p>'))];
        }

        $server = $this->server();
        $serverMinecraft = ServerTarget::minecraftVersion($server);
        $serverLoader = ServerTarget::loader($server);

        $components = [];

        if ($source === CurseForgePackProvider::ID && ($twin = $this->modrinthTwin((string) $project['title'], $project['loaders'] ?? [], $versions)) !== null) {
            $components[] = Section::make(trans('modpacks-plus::strings.details.also_on_modrinth_heading'))
                ->description(trans('modpacks-plus::strings.details.also_on_modrinth_help'))
                ->headerActions([
                    Action::make('also_on_modrinth')
                        ->label(trans('modpacks-plus::strings.details.also_on_modrinth'))
                        ->icon('tabler-box')
                        ->color('success')
                        ->cancelParentActions()
                        ->action(fn () => $this->showOnModrinth($twin)),
                ]);
        }

        $components[] = Html::make($this->markdown((string) $project['body'] !== '' ? (string) $project['body'] : (string) $project['description']));

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
                ? ($this->isUpdatable($source, $packId, $version['id'])
                    ? $this->makeUpdateAction('update_'.$index, $version['id'], $version['version_number'], $version)
                    : $this->makeInstallAction($index, $packId, $version, (string) $project['title'], $source))
                : $this->makeMismatchAction($index, $packId, $version, (string) $project['title'], $needs, self::switchTarget($version, $serverMinecraft, $serverLoader), $source);

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

    /**
     * The title of a Modrinth modpack with the same title (case-insensitive) and loader as a
     * CurseForge pack, or null. Only a suggestion: titles can collide, so nothing is automatic.
     *
     * @param  mixed  $loaders  the CurseForge pack's loaders
     * @param  array<int, array{loaders: array<int, string>}>  $versions
     */
    private function modrinthTwin(string $title, mixed $loaders, array $versions): ?string
    {
        $loader = (is_array($loaders) ? array_values(array_filter($loaders, 'is_string')) : [])[0] ?? ($versions[0]['loaders'][0] ?? null);
        $wanted = mb_strtolower(trim($title));
        if ($loader === null || $wanted === '') {
            return null;
        }

        try {
            $hits = $this->modrinth()->search($title, null, $loader, [], 'relevance', 1, 10)['hits'];
        } catch (PackUnavailableException $exception) {
            report($exception);

            return null;
        }

        foreach ($hits as $hit) {
            if (mb_strtolower(trim($hit['title'])) === $wanted) {
                return $hit['title'];
            }
        }

        return null;
    }

    /** Whether a version is another version of the installed pack, which can be updated in place. */
    private function isUpdatable(string $source, string $packId, string $versionId): bool
    {
        $installed = $this->lastInstalledPack();

        return $installed !== null && $installed->provider === $source && $installed->pack_id === $packId
            && $installed->version_id !== $versionId && $installed->manifestEntries() !== null;
    }

    /** The installed pack when the update check found a version it can be updated to in place. */
    private function bannerUpdate(): ?PackInstall
    {
        $installed = $this->lastInstalledPack();

        return $installed !== null && $installed->update_available && is_string($installed->latest_version_id)
            && $installed->latest_version_id !== $installed->version_id && $installed->manifestEntries() !== null ? $installed : null;
    }

    /**
     * "Update to <version>" with the "Overwrite my changes" choice (off by default).
     *
     * Like Install (plan 38), a version known not to fit the server's disk is disabled with the
     * reason, and the confirm text shows the pack size against the free space.
     *
     * @param  string|\Closure(): string  $versionId
     * @param  string|\Closure(): string  $versionNumber
     * @param  array{id: string, file?: array{size: int}|null}|null  $version  the version as listed (download size), if known
     */
    private function makeUpdateAction(string $name, string|\Closure $versionId, string|\Closure $versionNumber, ?array $version = null): Action
    {
        $number = fn (): string => $versionNumber instanceof \Closure ? $versionNumber() : $versionNumber;
        $id = fn (): string => $versionId instanceof \Closure ? $versionId() : $versionId;
        $data = fn (): array => $version ?? ['id' => $id()];
        $wontFit = fn (): ?string => ($installed = $this->lastInstalledPack()) !== null ? $this->wontFit($installed->provider, $installed->pack_id, $data()) : null;

        return Action::make($name)
            ->label(fn () => $wontFit() !== null ? trans('modpacks-plus::strings.install.no_room') : trans('modpacks-plus::strings.actions.update', ['version' => $number()]))
            ->icon('tabler-refresh')
            ->color('success')
            // Hidden without the install permissions; updatePack() re-checks them anyway.
            ->visible(fn () => static::canInstall())
            ->authorize(fn () => static::canInstall())
            ->disabled(fn () => $this->activeInstall() !== null || $this->serverBlocked() !== null || $wontFit() !== null)
            ->tooltip(fn () => $this->serverBlocked() ?? $wontFit())
            ->requiresConfirmation()
            ->modalHeading(fn () => trans('modpacks-plus::strings.install.update_heading', ['name' => (string) $this->lastInstalledPack()?->name, 'version' => $number()]))
            ->modalDescription(fn () => ($installed = $this->lastInstalledPack()) !== null
                ? $this->installDescription($installed->provider, $installed->pack_id, $data(), trans('modpacks-plus::strings.install.update_body'))
                : trans('modpacks-plus::strings.install.update_body'))
            ->modalSubmitActionLabel(fn () => trans('modpacks-plus::strings.actions.update', ['version' => $number()]))
            ->schema([
                Toggle::make('overwrite_changes')
                    ->label(trans('modpacks-plus::strings.install.overwrite_changes'))
                    ->helperText(trans('modpacks-plus::strings.install.overwrite_changes_help'))
                    ->default(false),
            ])
            ->action(fn (array $data) => $this->updatePack($id(), (bool) ($data['overwrite_changes'] ?? false)));
    }

    /** @param  array{id: string, version_number: string, name: string, file?: array{size: int}|null}  $version */
    private function makeInstallAction(int $index, string $packId, array $version, string $title, string $source = ModrinthPackProvider::ID): Action
    {
        $versionId = $version['id'];

        return Action::make('install_'.$index)
            ->label(fn () => $this->wontFit($source, $packId, $version) !== null ? trans('modpacks-plus::strings.install.no_room') : trans('modpacks-plus::strings.actions.install'))
            ->icon('tabler-download')
            ->color('success')
            // Hidden without the install permissions; installPack() re-checks them anyway.
            ->visible(fn () => static::canInstall())
            ->authorize(fn () => static::canInstall())
            // Plan 38: a version known not to fit is disabled with the reason; the prepare step
            // is still the real check for everything else.
            ->disabled(fn () => $this->activeInstall() !== null || $this->serverBlocked() !== null || $this->wontFit($source, $packId, $version) !== null)
            ->tooltip(fn () => $this->serverBlocked() ?? $this->wontFit($source, $packId, $version))
            ->requiresConfirmation()
            ->modalHeading(trans('modpacks-plus::strings.install.confirm_heading', ['name' => $title, 'version' => $version['version_number']]))
            ->modalDescription(fn () => $this->installDescription($source, $packId, $version))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.actions.install'))
            ->schema([self::freshWorldToggle()])
            ->action(fn (array $data) => $this->installPack($packId, $versionId, $source, (bool) ($data['fresh_world'] ?? false)));
    }

    /**
     * Plan 38: the server's disk budget for the page. The node is asked only when the server has
     * a disk limit, only from the confirm modal / install button (never for the pack list), with
     * a short timeout, and its answer is reused for a few seconds. Unknown = the configured cap.
     */
    private function diskBudget(): DiskBudget
    {
        if ($this->diskBudget !== null) {
            return $this->diskBudget;
        }

        $server = $this->server();
        $diskMb = max(0, (int) $server->disk);
        $used = null;
        if ($diskMb > 0) {
            $key = 'modpacks-plus:disk-used:'.$server->uuid;
            $cached = Cache::get($key);
            if (is_array($cached) && array_key_exists('used', $cached)) {
                $used = is_int($cached['used']) ? $cached['used'] : null;
            } else {
                $used = app(ServerDiskUsage::class)->usedBytes($server, self::DISK_USAGE_TIMEOUT);
                Cache::put($key, ['used' => $used], self::DISK_USAGE_CACHE_SECONDS);
            }
        }

        return $this->diskBudget = DiskBudget::forServer($diskMb, $used, Caps::fromConfig());
    }

    /**
     * The best size known for a pack version: the whole pack from an earlier check of this
     * version (exact), else its download (a lower bound), else nothing.
     *
     * @param  array{id: string, file?: array{size: int}|null}  $version
     * @return array{bytes: int, exact: bool}|null
     */
    private function packSize(string $source, string $packId, array $version): ?array
    {
        $known = PackSizes::known($source, $packId, $version['id']);
        if ($known !== null) {
            return ['bytes' => $known, 'exact' => true];
        }

        $download = $version['file']['size'] ?? null;

        return is_int($download) && $download > 0 ? ['bytes' => $download, 'exact' => false] : null;
    }

    /**
     * Plan 39: why the server can't take an install (its last install failed, or it is
     * suspended), or null. InstallEngine::start() refuses the same states.
     */
    private function serverBlocked(bool $forSwitch = false): ?string
    {
        $blocked = ServerAvailability::stuck($this->server());
        if ($forSwitch && $blocked === ServerAvailability::FAILED_INSTALL) {
            return null;
        }

        return $blocked === null ? null : trans('modpacks-plus::strings.install.server_'.$blocked);
    }

    /**
     * Why this version can't be installed here, or null when it may fit.
     *
     * @param  array{id: string, file?: array{size: int}|null}  $version
     */
    private function wontFit(string $source, string $packId, array $version): ?string
    {
        $size = $this->packSize($source, $packId, $version);
        if ($size === null) {
            return null;
        }

        $budget = $this->diskBudget();
        if (!$budget->fitsDisk($size['bytes'])) {
            return trans('modpacks-plus::strings.install.wont_fit', DiskBudget::detailParams($budget->detail($size['bytes'])));
        }

        if ($size['exact'] && $size['bytes'] > $budget->capBytes) {
            return trans('modpacks-plus::strings.install.over_cap', ['size' => DiskBudget::human($size['bytes']), 'cap' => DiskBudget::human($budget->capBytes)]);
        }

        return null;
    }

    /**
     * The confirm text plus the pack size against the server's free space.
     *
     * @param  array{id: string, file?: array{size: int}|null}  $version
     */
    private function installDescription(string $source, string $packId, array $version, ?string $intro = null): HtmlString
    {
        $lines = [$intro ?? trans('modpacks-plus::strings.install.confirm_body', ['folders' => 'mods/, config/, defaultconfigs/, kubejs/, scripts/'])];

        $size = $this->packSize($source, $packId, $version);
        $lines[] = match (true) {
            $size === null => trans('modpacks-plus::strings.install.pack_size_unknown'),
            $size['exact'] => trans('modpacks-plus::strings.install.pack_size', ['size' => DiskBudget::human($size['bytes'])]),
            default => trans('modpacks-plus::strings.install.pack_download', ['size' => DiskBudget::human($size['bytes'])]),
        };

        $budget = $this->diskBudget();
        $cap = DiskBudget::human($budget->capBytes);
        $lines[] = match (true) {
            !$budget->limited() => trans('modpacks-plus::strings.install.disk_unlimited', ['cap' => $cap]),
            !$budget->usageKnown() => trans('modpacks-plus::strings.install.disk_unknown', ['cap' => $cap]),
            default => trans('modpacks-plus::strings.install.disk_free', [
                'free' => DiskBudget::human($budget->freeBytes() ?? 0),
                'limit' => DiskBudget::human($budget->limitBytes),
                'headroom' => DiskBudget::human($budget->headroomBytes),
            ]),
        };

        $reason = $this->wontFit($source, $packId, $version);
        if ($reason !== null) {
            $lines[] = $reason;
        }

        return new HtmlString(implode('', array_map(fn (string $line) => '<p>'.e($line).'</p>', $lines)));
    }

    /**
     * A version for another loader or Minecraft version: "Switch to … and install" for
     * those allowed to switch, "ask an admin" for those who may install but not switch,
     * otherwise just what it needs.
     *
     * @param  array{id: string, version_number: string, name: string}  $version
     * @param  array{loader: string, minecraft: string}|null  $target
     */
    private function makeMismatchAction(int $index, string $packId, array $version, string $title, string $needs, ?array $target, string $source = ModrinthPackProvider::ID): Action
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
            // Plan 39: a switch reinstalls the server, so a failed install doesn't block it.
            ->disabled(fn () => $this->activeInstall() !== null || $this->serverBlocked(forSwitch: true) !== null)
            ->tooltip(fn () => $this->serverBlocked(forSwitch: true))
            ->requiresConfirmation()
            ->modalHeading(trans('modpacks-plus::strings.install.switch_heading', ['name' => $title, 'version' => $version['version_number'], 'target' => $needs]))
            ->modalDescription(fn () => $this->switchDescription($target))
            ->modalSubmitActionLabel(trans('modpacks-plus::strings.install.switch_confirm'))
            ->schema([self::freshWorldToggle()])
            ->action(fn (array $data) => $this->switchLoaderAndInstall($packId, $versionId, $source, (bool) ($data['fresh_world'] ?? false)));
    }

    /**
     * "Start a fresh world" (plan 36): off by default, so the world is kept. On, the world is
     * moved into `.modpacks-plus/previous-<date>/`, never deleted; the player lists stay.
     */
    private static function freshWorldToggle(): Toggle
    {
        return Toggle::make('fresh_world')
            ->label(trans('modpacks-plus::strings.install.fresh_world'))
            ->helperText(trans('modpacks-plus::strings.install.fresh_world_help'))
            ->default(false);
    }

    /** Markdown from Modrinth (or CurseForge's description as plain text), with raw HTML stripped and unsafe links dropped. */
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
            Callout::make(trans('modpacks-plus::strings.page.curseforge_not_configured'))
                ->description(trans('modpacks-plus::strings.reasons.curseforge_not_configured'))
                ->icon('tabler-key')
                ->color('warning')
                ->visible(fn () => $this->source() === CurseForgePackProvider::ID && !$this->sourceReady()),
            Callout::make(trans('modpacks-plus::strings.page.curseforge_mirror'))
                ->icon('tabler-info-circle')
                ->color('info')
                ->visible(fn () => $this->source() === CurseForgePackProvider::ID && CurseForgeClient::fromConfig()->mode() === CurseForgeClient::MODE_MIRROR),
            // Plan 32: CurseForge files inside FTB / ATLauncher packs need CurseForge (or Modrinth).
            Callout::make(trans('modpacks-plus::strings.page.ftb_curseforge'))
                ->icon('tabler-info-circle')
                ->color('info')
                ->visible(fn () => $this->source() === FtbPackProvider::ID && !CurseForgeClient::fromConfig()->configured()),
            Callout::make(trans('modpacks-plus::strings.page.atlauncher_curseforge'))
                ->icon('tabler-info-circle')
                ->color('info')
                ->visible(fn () => $this->source() === AtLauncherPackProvider::ID && !CurseForgeClient::fromConfig()->configured()),
            Callout::make(trans('modpacks-plus::strings.page.unsupported'))
                ->description(trans('modpacks-plus::strings.reasons.server_not_supported'))
                ->icon('tabler-alert-triangle')
                ->color('warning')
                ->visible(fn () => ServerTarget::minecraftVersion($server) === null || ServerTarget::loader($server) === null),
            Callout::make(fn () => ($discarded = $this->recentlyDiscarded()) !== null ? trans('modpacks-plus::strings.page.discarded_banner', [
                'date' => $discarded->restored_at?->timezone(user()->timezone ?? 'UTC')->toDayDateTimeString() ?? '',
                'path' => (string) $discarded->preserved_path,
            ]) : '')
                ->icon('tabler-archive-off')
                ->color('warning')
                ->visible(fn () => $this->recentlyDiscarded() !== null),
            Callout::make(fn () => trans('modpacks-plus::strings.page.installed_pack', [
                'name' => (string) $this->lastInstalledPack()?->name,
                'version' => (string) $this->lastInstalledPack()?->version_number,
            ]))
                ->description(fn () => ($installed = $this->lastInstalledPack()) !== null && $installed->update_available
                    ? trans('modpacks-plus::strings.page.update_available', ['version' => (string) $installed->latest_version_number])
                    : $this->lastInstalledPack()?->finished_at?->diffForHumans())
                ->icon('tabler-package')
                ->color(fn () => $this->lastInstalledPack()?->update_available ? 'info' : 'success')
                ->actions([
                    $this->makeUpdateAction('update_banner', fn () => (string) $this->bannerUpdate()?->latest_version_id, fn () => (string) $this->bannerUpdate()?->latest_version_number)
                        ->visible(fn () => static::canInstall() && $this->bannerUpdate() !== null),
                ])
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
                    Action::make('discard_restore')
                        ->label(trans('modpacks-plus::strings.actions.discard_restore'))
                        ->icon('tabler-archive-off')
                        ->color('danger')
                        ->authorize(fn () => static::canInstall())
                        ->visible(fn () => static::canInstall() && ($candidate = PackInstall::restoreCandidate($server->id)) !== null && $candidate->hasPreservedPending() && !$candidate->isRestoring())
                        ->requiresConfirmation()
                        ->modalHeading(trans('modpacks-plus::strings.restore.discard_heading'))
                        ->modalDescription(fn () => trans('modpacks-plus::strings.restore.discard_body', ['path' => (string) PackInstall::restoreCandidate($server->id)?->preserved_path]))
                        ->modalSubmitActionLabel(trans('modpacks-plus::strings.actions.discard_restore'))
                        ->action(fn () => $this->discardPendingRestore()),
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
