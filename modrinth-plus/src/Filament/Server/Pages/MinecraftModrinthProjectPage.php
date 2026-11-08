<?php

namespace DrMxrcy\ModrinthPlus\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Enums\ModrinthSearchIndex;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Support\Compatibility;
use DrMxrcy\ModrinthPlus\Support\DependencyResolver;
use DrMxrcy\ModrinthPlus\Support\Sides;
use DrMxrcy\ModrinthPlus\Support\UnmatchedJars;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

abstract class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    /** Table record key of a "Not on Modrinth" row: this prefix plus the jar's file name. */
    public const NOT_ON_MODRINTH_KEY_PREFIX = 'file:';

    /** @var array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    /** Per-visit override of the hide_client_only config; Livewire state only, never persisted. */
    public bool $showClientOnly = false;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    protected static ?ModrinthProjectType $modrinthProjectType = null;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && static::$modrinthProjectType
            && in_array(static::$modrinthProjectType, ModrinthProjectType::fromServer($server))
            && static::userCan(SubuserPermission::FileRead);
    }

    /** Installing a project that is not on the server yet writes a new jar. */
    public static function canInstall(): bool
    {
        return static::userCan(SubuserPermission::FileCreate);
    }

    /** Updating writes the new jar and deletes the old one. */
    public static function canUpdate(): bool
    {
        return static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete);
    }

    public static function canUninstall(): bool
    {
        return static::userCan(SubuserPermission::FileDelete);
    }

    /**
     * "Scan folder" reads every untracked jar's contents to hash it (file.read-content) and
     * writes the metadata file, which it may have to create (file.create + file.update). It
     * never writes, renames or deletes a jar.
     */
    public static function canScan(): bool
    {
        return static::userCan(SubuserPermission::FileReadContent, SubuserPermission::FileCreate, SubuserPermission::FileUpdate);
    }

    /**
     * Whether the current user holds every given permission on the current server. The
     * daemon file calls run with the server's own credentials, so the panel side is the only
     * place subuser file permissions are enforced. Owners and admins pass through User::can().
     */
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

    /**
     * Installing (the latest or a specific version) over a project that is already installed
     * replaces its jar, so it needs the update permissions rather than just file.create.
     *
     * @param  array<string, mixed>  $record
     */
    protected function canInstallProject(array $record): bool
    {
        return is_null($this->getInstalledMod($record['project_id'])) ? static::canInstall() : static::canUpdate();
    }

    public static function getNavigationLabel(): string
    {
        return static::$modrinthProjectType?->getLabel() ?? 'Modrinth';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->loadDefaultActiveTab();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('modrinth-plus::strings.page.view_all')),
            'installed' => Tab::make(trans('modrinth-plus::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            $this->installedModsMetadata = MinecraftModrinth::getInstalledModsMetadata($server, static::$modrinthProjectType);
        }

        return $this->installedModsMetadata;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = MinecraftModrinth::getProjectVersions($projectId, $server);
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * Fetch the versions of every project on the current page up front.
     *
     * Each installed row asks for its versions to decide between the "installed" and "update"
     * action, so without this the installed tab does one Modrinth request per row, one after
     * the other, and a full page can take longer than the request is allowed to run.
     *
     * @param  array<int|string, string>  $projectIds
     */
    protected function primeVersionsCache(array $projectIds): void
    {
        $projectIds = array_values(array_diff(array_unique(array_filter($projectIds)), array_keys($this->versionsCache)));

        if (count($projectIds) < 2) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        // A failed request (null) is cached as "no versions" for this render only, like before,
        // so an outage doesn't fall back to one slow request per row.
        $this->versionsCache += array_map(fn (?array $versions) => $versions ?? [], MinecraftModrinth::getProjectVersionsBulk($projectIds, $server));
    }

    /**
     * Drop everything read from the server so the next render reflects what is actually on disk.
     */
    protected function forgetInstalledState(bool $refresh = true): void
    {
        $this->installedModsMetadata = null;
        $this->versionsCache = [];

        if ($refresh) {
            $this->js('$wire.$refresh()');
        }
    }

    /**
     * Jars on disk the metadata doesn't list and the last scan found no Modrinth match for.
     * An unreachable daemon shows none rather than failing the whole Installed tab.
     *
     * @return list<array{name: string, size: int, modified: ?string}>
     */
    protected function notOnModrinthFiles(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $listing = MinecraftModrinth::listFolder($server, static::$modrinthProjectType->getFolder());
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        return UnmatchedJars::notOnModrinth($server->id, static::$modrinthProjectType, UnmatchedJars::untracked($listing, $this->getInstalledModsMetadata()));
    }

    /**
     * A read-only Installed-tab row for a jar that is not on Modrinth: no project, no versions,
     * no link; only uninstall (deleting the file) is offered. The row is keyed by its file name,
     * not its position, so an action on it can never land on another jar after the list shifts.
     *
     * @param  array{name: string, size: int, modified: ?string}  $file
     * @return array<string, mixed>
     */
    protected function notOnModrinthRecord(array $file): array
    {
        return [
            ArrayRecord::getKeyName() => self::NOT_ON_MODRINTH_KEY_PREFIX . $file['name'],
            'project_id' => '',
            'slug' => '',
            'title' => $file['name'],
            'description' => trans('modrinth-plus::strings.scan.row_description'),
            'icon_url' => null,
            'author' => '',
            'downloads' => 0,
            'date_modified' => $file['modified'],
            'project_type' => '',
            'unavailable' => true,
            'not_on_modrinth' => true,
            'filename' => $file['name'],
        ];
    }

    /** @param  array<string, mixed>  $record */
    protected function isNotOnModrinthRecord(array $record): bool
    {
        return !empty($record['not_on_modrinth']);
    }

    /**
     * @param  array<int, array{primary: bool, filename: string, url: string}>  $files
     * @return array{primary: bool, filename: string, url: string}|null
     */
    protected function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $folder = static::$modrinthProjectType->getFolder();

        MinecraftModrinth::downloadFile($server, $primaryFile['url'], $folder, $safeNewFilename);

        $saved = MinecraftModrinth::saveModMetadata(
            $server,
            static::$modrinthProjectType,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null
        );

        if (!$saved) {
            if (!$oldFilename || $oldFilename !== $safeNewFilename) {
                try {
                    MinecraftModrinth::deleteFile($server, $folder, $safeNewFilename);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                MinecraftModrinth::deleteFile($server, $folder, $oldFilename);
            } catch (Exception $deleteException) {
                try {
                    MinecraftModrinth::deleteFile($server, $folder, $safeNewFilename);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedMod && !MinecraftModrinth::saveModMetadata(
                    $server,
                    static::$modrinthProjectType,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }
    }

    /**
     * Installs or updates to a version, then the required dependencies it pulls in. Once the
     * project itself is on disk nothing here undoes it: dependency problems are only reported.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     * @return array{installed: list<string>, failed: list<string>, manual: list<string>, skipped: list<string>, unchecked: list<string>, error: bool}
     *
     * @throws Exception when the project itself could not be installed
     */
    private function installWithDependencies(Server $server, array $record, array $versionData, array $primaryFile, ?array $installedMod): array
    {
        $this->performInstallOrUpdate($server, $record, $versionData, $primaryFile, $installedMod);

        try {
            return $this->installRequiredDependencies($server, $record['project_id'], $versionData);
        } catch (Exception $exception) {
            report($exception);

            return ['installed' => [], 'failed' => [], 'manual' => [], 'skipped' => [], 'unchecked' => [], 'error' => true];
        }
    }

    /**
     * Downloads every missing required dependency of $versionData (see DependencyResolver) and
     * records it with `installed_as_dependency_of`. Each download is a new file, so the
     * file.create permission is checked again before every one.
     *
     * Client-only dependencies (server_side = unsupported) are skipped: they do nothing on a
     * server and can keep it from starting. So are dependencies whose project record could not be
     * fetched, since their sides are unknown. A dependency is not installed when every project that
     * requires it was skipped or failed, since nothing on the server needs it then; one still
     * required by an installed (or not yet handled) project is. `installed_as_dependency_of` is
     * the first of its parents that got installed.
     *
     * @param  array<string, mixed>  $versionData
     * @return array{installed: list<string>, failed: list<string>, manual: list<string>, skipped: list<string>, unchecked: list<string>, error: bool}
     *
     * @throws Exception
     */
    private function installRequiredDependencies(Server $server, string $projectId, array $versionData): array
    {
        $report = ['installed' => [], 'failed' => [], 'manual' => [], 'skipped' => [], 'unchecked' => [], 'error' => false];

        if (!DependencyResolver::hasRequiredDependencies($versionData)) {
            return $report;
        }

        // Unreadable metadata would make every dependency look missing and download it again.
        $installedMods = MinecraftModrinth::readInstalledModsMetadata($server, static::$modrinthProjectType);

        if ($installedMods === null) {
            $report['error'] = true;

            return $report;
        }

        $installedIds = array_column($installedMods, 'project_id');

        $plan = DependencyResolver::resolve($projectId, $versionData, function (string $dependencyId) use ($server) {
            $versions = MinecraftModrinth::getProjectVersionsBulk([$dependencyId], $server);

            return $versions[$dependencyId] ?? null;
        }, $installedIds);

        $projects = MinecraftModrinth::getProjectsByIds(array_values(array_filter(array_merge(
            array_column($plan['install'], 'project_id'),
            array_column($plan['unresolved'], 'project_id'),
        ))));

        $title = fn (string $id): string => is_string($projects[$id]['title'] ?? null) ? $projects[$id]['title'] : $id;

        $folder = static::$modrinthProjectType->getFolder();

        /** @var array<string, true> $notInstalled */
        $notInstalled = [];

        /** @var array<string, true> $installed the project itself plus every dependency installed so far */
        $installed = [$projectId => true];

        // Parents of an entry that are not known to be missing.
        $neededBy = function (array $entry) use (&$notInstalled): array {
            /** @var array{required_by_all: list<string>} $entry */
            return array_values(array_filter($entry['required_by_all'], fn (string $parent) => !isset($notInstalled[$parent])));
        };

        foreach ($plan['install'] as $entry) {
            $dependencyId = $entry['project_id'];
            $project = $projects[$dependencyId] ?? null;

            $parents = $neededBy($entry);

            if ($parents === []) {
                $notInstalled[$dependencyId] = true;

                continue;
            }

            // A parent not handled yet (it comes later in the list) counts as needing it.
            $installedParents = array_values(array_filter($parents, fn (string $parent) => isset($installed[$parent])));
            $parent = $installedParents[0] ?? $parents[0];

            // Fail closed: without the project record (Modrinth outage) a client-only dependency
            // can't be told apart, and installing one can keep the server from starting.
            if ($project === null) {
                $report['unchecked'][] = $title($dependencyId);
                $notInstalled[$dependencyId] = true;

                continue;
            }

            if (Sides::isClientOnly($project)) {
                $report['skipped'][] = $title($dependencyId);
                $notInstalled[$dependencyId] = true;

                continue;
            }

            if (!static::canInstall()) {
                $report['failed'][] = $title($dependencyId);
                $notInstalled[$dependencyId] = true;

                continue;
            }

            try {
                $version = $entry['version'];
                $primaryFile = $this->getPrimaryFile(is_array($version['files'] ?? null) ? $version['files'] : []);

                if (!$primaryFile) {
                    throw new Exception("No downloadable file found for dependency $dependencyId");
                }

                $filename = $this->validateFilename($primaryFile['filename']);

                MinecraftModrinth::downloadFile($server, $primaryFile['url'], $folder, $filename);

                $saved = MinecraftModrinth::saveModMetadata(
                    $server,
                    static::$modrinthProjectType,
                    $dependencyId,
                    is_string($project['slug'] ?? null) ? $project['slug'] : $dependencyId,
                    $title($dependencyId),
                    (string) ($version['id'] ?? ''),
                    (string) ($version['version_number'] ?? ''),
                    $filename,
                    null,
                    $parent,
                );

                if (!$saved) {
                    try {
                        MinecraftModrinth::deleteFile($server, $folder, $filename);
                    } catch (Exception $rollbackException) {
                        report($rollbackException);
                    }

                    throw new Exception("Failed to save metadata for dependency $dependencyId");
                }

                $installed[$dependencyId] = true;
                $report['installed'][] = $title($dependencyId);
            } catch (Exception $exception) {
                report($exception);

                $report['failed'][] = $title($dependencyId);
                $notInstalled[$dependencyId] = true;
            }
        }

        foreach ($plan['unresolved'] as $entry) {
            if ($neededBy($entry) === []) {
                continue;
            }

            if ($entry['reason'] === DependencyResolver::REASON_MANUAL) {
                $report['manual'][] = $entry['file_name'] ?? $entry['version_id'] ?? '?';
            } else {
                $report['failed'][] = $title((string) $entry['project_id']);
            }
        }

        return $report;
    }

    /**
     * The warning shown before installing a version that declares an installed project as
     * `incompatible`, or null when there is none. It only warns; installing stays possible.
     *
     * @param  array<string, mixed>|null  $versionData
     */
    protected function incompatibleWarning(?array $versionData): ?string
    {
        if ($versionData === null) {
            return null;
        }

        $installed = array_column($this->getInstalledModsMetadata(), 'project_title', 'project_id');
        $conflicts = DependencyResolver::incompatibleInstalled($versionData, array_map('strval', array_keys($installed)));

        if ($conflicts === []) {
            return null;
        }

        return trans('modrinth-plus::strings.dependencies.incompatible_description', [
            'list' => implode(', ', array_map(fn (string $id) => $installed[$id], $conflicts)),
        ]);
    }

    /**
     * The success body plus the dependencies that were pulled in.
     *
     * @param  array{installed: list<string>, failed: list<string>, manual: list<string>, skipped: list<string>, unchecked: list<string>, error: bool}  $report
     */
    private function withInstalledDependencies(string $body, array $report): string
    {
        if ($report['installed'] === []) {
            return $body;
        }

        return $body.'. '.trans('modrinth-plus::strings.dependencies.installed', ['list' => implode(', ', $report['installed'])]);
    }

    /**
     * A separate warning for required dependencies that are not on the server now.
     *
     * @param  array{installed: list<string>, failed: list<string>, manual: list<string>, skipped: list<string>, unchecked: list<string>, error: bool}  $report
     */
    private function notifyDependencyProblems(array $report): void
    {
        $lines = [];

        foreach (['failed', 'manual', 'skipped', 'unchecked'] as $kind) {
            if ($report[$kind] !== []) {
                $lines[] = trans("modrinth-plus::strings.dependencies.$kind", ['list' => implode(', ', $report[$kind])]);
            }
        }

        if ($report['error']) {
            $lines[] = trans('modrinth-plus::strings.dependencies.error');
        }

        if ($lines === []) {
            return;
        }

        Notification::make()
            ->title(trans('modrinth-plus::strings.dependencies.problems_title'))
            ->body(implode(' ', $lines))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * The install button of one version in the versions modal.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array{primary: bool, filename: string, url: string}|null  $primaryFile
     */
    protected function makeInstallVersionAction(int|string $versionIndex, array $record, array $versionData, ?array $primaryFile): Action
    {
        return Action::make('install_version_' . $versionIndex)
            ->label(trans('modrinth-plus::strings.actions.install'))
            ->icon('tabler-download')
            ->visible($primaryFile !== null)
            ->authorize(fn () => $this->canInstallProject($record))
            // Only opens a confirmation when the version is incompatible with something installed.
            ->requiresConfirmation(fn () => $this->incompatibleWarning($versionData) !== null)
            ->modalHeading(fn () => $this->incompatibleWarning($versionData) !== null ? trans('modrinth-plus::strings.dependencies.incompatible_heading') : null)
            ->modalDescription(fn () => $this->incompatibleWarning($versionData))
            ->action(function () use ($record, $versionData, $primaryFile) {
                abort_unless($this->canInstallProject($record), 403);

                try {
                    /** @var Server $server */
                    $server = Filament::getTenant();

                    if (!$primaryFile) {
                        throw new Exception('No downloadable file found');
                    }

                    $installedMod = $this->getInstalledMod($record['project_id']);

                    $dependencies = $this->installWithDependencies($server, $record, $versionData, $primaryFile, $installedMod);

                    $this->forgetInstalledState();

                    Notification::make()
                        ->title(trans('modrinth-plus::strings.notifications.install_success'))
                        ->body($this->withInstalledDependencies(trans('modrinth-plus::strings.notifications.install_success_body', [
                            'name' => $record['title'],
                            'version' => $versionData['version_number'],
                        ]), $dependencies))
                        ->success()
                        ->send();

                    $this->notifyDependencyProblems($dependencies);
                } catch (Exception $exception) {
                    report($exception);

                    $this->forgetInstalledState();

                    Notification::make()
                        ->title(trans('modrinth-plus::strings.notifications.install_failed'))
                        ->body(trans('modrinth-plus::strings.notifications.install_failed_body'))
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @throws Exception
     */
    /**
     * A small badge for the title column's description; `$key` names a `strings.php` entry
     * whose `_tooltip` sibling holds the tooltip. Blade escapes both.
     */
    private function renderBadge(string $color, string $icon, string $key): string
    {
        return Blade::render('<x-filament::badge :color="$color" size="sm" :icon="$icon" :tooltip="$tooltip">{{ $label }}</x-filament::badge>', [
            'color' => $color,
            'icon' => $icon,
            'label' => trans("modrinth-plus::strings.$key"),
            'tooltip' => trans("modrinth-plus::strings.{$key}_tooltip"),
        ]);
    }

    /**
     * Sort picked in the table filter; null (nothing picked, or a tampered value) lets the
     * service choose relevance/downloads from the search term.
     *
     * @param  array<string, mixed>|null  $filters  Filament table filter state
     */
    protected function selectedSearchIndex(?array $filters): ?ModrinthSearchIndex
    {
        $value = is_array($filters['sort'] ?? null) ? ($filters['sort']['value'] ?? null) : null;

        return is_string($value) ? ModrinthSearchIndex::tryFrom($value) : null;
    }

    /**
     * @param  array<string, mixed>|null  $filters  Filament table filter state
     * @return array<int, string>
     */
    protected function selectedCategories(?array $filters): array
    {
        $values = is_array($filters['categories'] ?? null) ? ($filters['categories']['values'] ?? null) : null;

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, ?array $filters) {
                /** @var Server $server */
                $server = Filament::getTenant();

                if ($this->activeTab === 'installed') {
                    $installedMods = $this->getInstalledModsMetadata();

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedMods = array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                            return str_contains(strtolower($mod['project_title']), $searchLower)
                                || str_contains(strtolower($mod['project_slug']), $searchLower);
                        }));
                    }

                    // Sort by title so a row keeps its place instead of moving around whenever its
                    // metadata entry is rewritten by an install, update or failed update.
                    usort($installedMods, fn (array $a, array $b) => strcasecmp($a['project_title'], $b['project_title']));

                    $projects = MinecraftModrinth::getInstalledModsFromModrinth($installedMods, $page);

                    $this->primeVersionsCache(array_column($projects, 'project_id'));

                    // "Not on Modrinth" jars come after every tracked project and fill up the page.
                    $unmatched = $this->notOnModrinthFiles();

                    if ($search) {
                        $unmatched = array_values(array_filter($unmatched, fn (array $file) => str_contains(strtolower($file['name']), strtolower($search))));
                    }

                    $unmatchedOffset = max(0, ($page - 1) * 20 - count($installedMods));

                    foreach (array_slice($unmatched, $unmatchedOffset, 20 - count($projects)) as $file) {
                        $projects[] = $this->notOnModrinthRecord($file);
                    }

                    return new LengthAwarePaginator($projects, count($installedMods) + count($unmatched), 20, $page);
                } else {
                    $response = MinecraftModrinth::getProjects(
                        $server,
                        static::$modrinthProjectType,
                        $page,
                        $search,
                        $this->showClientOnly,
                        index: $this->selectedSearchIndex($filters),
                        categories: $this->selectedCategories($filters),
                    );

                    // getProjects() already looked up every hit's versions to filter the page; reuse
                    // them instead of fetching again. A failed lookup (null) counts as "no versions"
                    // for this render only, matching primeVersionsCache().
                    $this->versionsCache += array_map(fn (?array $versions) => $versions ?? [], $response['versions']);

                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            // Records are array-backed, so these filters never touch a query: records() reads their
            // state and hands it to the Modrinth search. Changing one resets to page 1 (Filament).
            ->filters([
                SelectFilter::make('sort')
                    ->label(trans('modrinth-plus::strings.filters.sort'))
                    ->options(ModrinthSearchIndex::class)
                    ->placeholder(trans('modrinth-plus::strings.filters.sort_default'))
                    ->visible(fn () => $this->activeTab !== 'installed'),
                SelectFilter::make('categories')
                    ->label(trans('modrinth-plus::strings.filters.categories'))
                    ->multiple()
                    ->options(fn () => collect(MinecraftModrinth::getCategories(static::$modrinthProjectType))
                        ->mapWithKeys(fn (string $category) => [$category => Str::headline($category)])
                        ->all())
                    ->visible(fn () => $this->activeTab !== 'installed'),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->headerActions([
                Action::make('toggle_client_only')
                    ->label(fn () => trans($this->showClientOnly ? 'modrinth-plus::strings.client_only.hide' : 'modrinth-plus::strings.client_only.show'))
                    ->tooltip(trans('modrinth-plus::strings.client_only.tooltip'))
                    ->icon(fn () => $this->showClientOnly ? 'tabler-eye-off' : 'tabler-eye')
                    ->color(fn () => $this->showClientOnly ? 'warning' : 'gray')
                    // Only meaningful on the "All" tab and while the config hides client-only projects.
                    ->visible(fn () => $this->activeTab !== 'installed' && (bool) config('modrinth-plus.hide_client_only', true))
                    ->action(function () {
                        $this->showClientOnly = !$this->showClientOnly;

                        $this->resetPage();
                        $this->flushCachedTableRecords();
                    }),
            ])
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(function (array $record) {
                        $description = (strlen($record['description']) > 120) ? substr($record['description'], 0, 120).'...' : $record['description'];

                        if ($this->isNotOnModrinthRecord($record)) {
                            $badge = $this->renderBadge('gray', 'tabler-file-unknown', 'scan.badge');
                        } elseif (Sides::playersNeedIt($record)) {
                            $badge = $this->renderBadge('warning', 'tabler-users', 'sides.players_need_it');
                        } elseif ($this->activeTab === 'installed' && Sides::isClientOnly($record)) {
                            $badge = $this->renderBadge('danger', 'tabler-alert-triangle', 'client_only.badge');
                        } else {
                            return $description;
                        }

                        return new HtmlString($badge.' '.e($description));
                    }),
                TextColumn::make('author')
                    ->url(fn ($state) => "https://modrinth.com/user/$state", true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable'])) {
                    return null;
                }

                return "https://modrinth.com/{$record['project_type']}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('modrinth-plus::strings.actions.versions'))
                    ->visible(fn (array $record) => empty($record['unavailable']))
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('modrinth-plus::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('modrinth-plus::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('modrinth-plus::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('modrinth-plus::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('modrinth-plus::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = $this->makeInstallVersionAction($versionIndex, $record, $versionData, $primaryFile);
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('modrinth-plus::strings.actions.install_latest'))
                    ->visible(function (array $record) {
                        if ($this->isNotOnModrinthRecord($record)) {
                            return false;
                        }

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return is_null($installedMod);
                    })
                    ->authorize(fn (array $record) => $this->canInstallProject($record))
                    // Only opens a confirmation when the latest version is incompatible with something installed.
                    ->requiresConfirmation(fn (array $record) => $this->incompatibleWarning($this->getCachedVersions($record['project_id'])[0] ?? null) !== null)
                    ->modalHeading(fn (array $record) => $this->incompatibleWarning($this->getCachedVersions($record['project_id'])[0] ?? null) !== null ? trans('modrinth-plus::strings.dependencies.incompatible_heading') : null)
                    ->modalDescription(fn (array $record) => $this->incompatibleWarning($this->getCachedVersions($record['project_id'])[0] ?? null))
                    ->action(function (array $record) {
                        abort_unless($this->canInstallProject($record), 403);

                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $versions = MinecraftModrinth::getProjectVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            // A stale page can still offer this on a project installed since; replace
                            // its jar instead of leaving the old one next to the new one.
                            $installedMod = $this->getInstalledMod($record['project_id']);

                            $dependencies = $this->installWithDependencies($server, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.install_success'))
                                ->body($this->withInstalledDependencies(trans('modrinth-plus::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]), $dependencies))
                                ->success()
                                ->send();

                            $this->notifyDependencyProblems($dependencies);
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.install_failed'))
                                ->body(trans('modrinth-plus::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('modrinth-plus::strings.actions.update'))
                    ->visible(function (array $record) {
                        // A client-only mod does nothing on a server: offer uninstall, not an update.
                        if (Sides::isClientOnly($record)) {
                            return false;
                        }

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return false;
                        }

                        return $installedMod['version_id'] !== $versions[0]['id'];
                    })
                    ->authorize(fn () => static::canUpdate())
                    ->requiresConfirmation()
                    ->modalHeading(trans('modrinth-plus::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $versions = $this->getCachedVersions($record['project_id']);

                        $description = trans('modrinth-plus::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $versions[0]['version_number'] ?? 'unknown',
                        ]);

                        $warning = $this->incompatibleWarning($versions[0] ?? null);

                        return $warning === null ? $description : "$description $warning";
                    })
                    ->action(function (array $record) {
                        abort_unless(static::canUpdate(), 403);
                        abort_if(Sides::isClientOnly($record), 403);

                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $versions = MinecraftModrinth::getProjectVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            // A new version can require dependencies the old one didn't.
                            $dependencies = $this->installWithDependencies($server, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.update_success'))
                                ->body($this->withInstalledDependencies(trans('modrinth-plus::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]), $dependencies))
                                ->success()
                                ->send();

                            $this->notifyDependencyProblems($dependencies);
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.update_failed'))
                                ->body(trans('modrinth-plus::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('modrinth-plus::strings.actions.installed'))
                    ->disabled()
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return true;
                        }

                        return $installedMod['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('modrinth-plus::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        return $this->isNotOnModrinthRecord($record) || !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->authorize(fn () => static::canUninstall())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('modrinth-plus::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('modrinth-plus::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record) {
                        abort_unless(static::canUninstall(), 403);

                        if ($this->isNotOnModrinthRecord($record)) {
                            $this->uninstallNotOnModrinth($record);

                            return;
                        }

                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $safeFilename = $this->validateFilename($installedMod['filename']);

                            $folder = static::$modrinthProjectType->getFolder();

                            MinecraftModrinth::deleteFile($server, $folder, $safeFilename);

                            $metadataRemoved = MinecraftModrinth::removeModMetadata($server, static::$modrinthProjectType, $record['project_id']);

                            $this->forgetInstalledState();

                            if (!$metadataRemoved) {
                                Log::warning('Failed to remove mod metadata after successful file deletion', [
                                    'project_id' => $record['project_id'],
                                    'server_id' => $server->id,
                                ]);

                                // The jar is gone but the metadata file still lists it, so the row
                                // stays. Say so instead of reporting a clean uninstall.
                                Notification::make()
                                    ->title(trans('modrinth-plus::strings.notifications.uninstall_partial'))
                                    ->body(trans('modrinth-plus::strings.notifications.uninstall_partial_body', [
                                        'name' => $record['title'],
                                    ]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.uninstall_success'))
                                ->body(trans('modrinth-plus::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-plus::strings.notifications.uninstall_failed'))
                                ->body(trans('modrinth-plus::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        if (!static::$modrinthProjectType) {
            return [];
        }

        $folder = static::$modrinthProjectType->getFolder();

        return [
            $this->makeScanFolderAction(),
            $this->makeCheckCompatibilityAction(),
            Action::make('open_folder')
                ->tooltip(fn () => trans('modrinth-plus::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
        ];
    }

    /**
     * Imports jars dropped into the folder by hand: matches on Modrinth (by file hash) become
     * normal installed projects, the rest show as "Not on Modrinth" rows. Only the metadata file
     * is written; no jar is deleted, renamed or replaced.
     */
    protected function makeScanFolderAction(): Action
    {
        return Action::make('scan_folder')
            ->label(trans('modrinth-plus::strings.scan.action'))
            ->tooltip(trans('modrinth-plus::strings.scan.tooltip'))
            ->icon('tabler-file-search')
            ->color('gray')
            ->authorize(fn () => static::canScan())
            ->requiresConfirmation()
            ->modalIcon('tabler-file-search')
            ->modalHeading(trans('modrinth-plus::strings.scan.heading'))
            ->modalDescription(fn () => trans('modrinth-plus::strings.scan.description', ['folder' => static::$modrinthProjectType->getFolder()]))
            ->modalSubmitActionLabel(trans('modrinth-plus::strings.scan.submit'))
            ->action(function () {
                abort_unless(static::canScan(), 403);

                /** @var Server $server */
                $server = Filament::getTenant();

                try {
                    $result = MinecraftModrinth::scanAndImport($server, static::$modrinthProjectType);
                } catch (Exception $exception) {
                    report($exception);

                    $this->forgetInstalledState();

                    Notification::make()
                        ->title(trans('modrinth-plus::strings.scan.failed'))
                        ->body(trans('modrinth-plus::strings.scan.failed_body'))
                        ->danger()
                        ->send();

                    return;
                }

                $this->forgetInstalledState();
                $this->flushCachedTableRecords();

                $lines = [];

                if ($result['imported'] !== []) {
                    $lines[] = trans('modrinth-plus::strings.scan.imported', ['count' => count($result['imported']), 'list' => implode(', ', $result['imported'])]);
                }

                foreach (['not_on_modrinth', 'duplicates', 'skipped'] as $kind) {
                    if ($result[$kind] !== []) {
                        $lines[] = trans("modrinth-plus::strings.scan.$kind", ['count' => count($result[$kind]), 'list' => implode(', ', $result[$kind])]);
                    }
                }

                if ($result['error']) {
                    $lines[] = trans('modrinth-plus::strings.scan.error');
                }

                $notification = Notification::make()
                    ->title(trans($result['error'] ? 'modrinth-plus::strings.scan.finished_with_problems' : 'modrinth-plus::strings.scan.finished'))
                    ->body($lines === [] ? trans('modrinth-plus::strings.scan.nothing_new') : implode(' ', $lines));

                if ($result['error']) {
                    $notification->warning()->persistent();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * Deletes a "Not on Modrinth" jar. The file name is checked against the folder again, so
     * only a jar that is still untracked and still listed as not on Modrinth can be deleted here.
     *
     * @param  array<string, mixed>  $record
     */
    protected function uninstallNotOnModrinth(array $record): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $filename = $this->validateFilename(is_string($record['filename'] ?? null) ? $record['filename'] : '');

            if (!in_array($filename, array_column($this->notOnModrinthFiles(), 'name'), true)) {
                throw new Exception("$filename is not an untracked jar that is not on Modrinth");
            }

            MinecraftModrinth::deleteFile($server, static::$modrinthProjectType->getFolder(), $filename);

            UnmatchedJars::forget($server->id, static::$modrinthProjectType, $filename);

            $this->forgetInstalledState();

            Notification::make()
                ->title(trans('modrinth-plus::strings.notifications.uninstall_success'))
                ->body(trans('modrinth-plus::strings.notifications.uninstall_success_body', ['name' => $filename]))
                ->success()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            $this->forgetInstalledState();

            Notification::make()
                ->title(trans('modrinth-plus::strings.notifications.uninstall_failed'))
                ->body(trans('modrinth-plus::strings.notifications.uninstall_failed_body'))
                ->danger()
                ->send();
        }
    }

    /**
     * Read-only: lists the installed projects that have no build for the server's current
     * Minecraft version + loader. Never downloads, deletes or rewrites metadata; the update
     * row action stays the way to act on the result.
     */
    protected function makeCheckCompatibilityAction(): Action
    {
        return Action::make('check_compatibility')
            ->label(trans('modrinth-plus::strings.compatibility.action'))
            ->tooltip(trans('modrinth-plus::strings.compatibility.tooltip'))
            ->icon('tabler-plug-connected')
            ->color('gray')
            ->authorize(fn () => static::userCan(SubuserPermission::FileRead))
            ->modalHeading(trans('modrinth-plus::strings.compatibility.heading'))
            ->modalDescription(function () {
                /** @var Server $server */
                $server = Filament::getTenant();
                $unknown = trans('modrinth-plus::strings.page.unknown');

                return trans('modrinth-plus::strings.compatibility.description', [
                    'version' => MinecraftModrinth::getMinecraftVersion($server) ?? $unknown,
                    'loader' => MinecraftModrinth::getLoaderFromServer($server)['display_name'] ?? $unknown,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('modrinth-plus::strings.compatibility.close'))
            ->schema(fn () => $this->compatibilitySchema());
    }

    /** @return array<int, TextEntry> */
    protected function compatibilitySchema(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $installedMods = $this->getInstalledModsMetadata();

        if ($installedMods === []) {
            return [
                TextEntry::make('compatibility_none_installed')
                    ->hiddenLabel()
                    ->state(trans('modrinth-plus::strings.compatibility.none_installed')),
            ];
        }

        // Asks Modrinth directly: the page's versionsCache turns a failed lookup into "no
        // versions", which would report an outage as incompatible.
        $loaderKnown = MinecraftModrinth::getLoaderFromServer($server) !== null;
        $versions = $loaderKnown ? MinecraftModrinth::getProjectVersionsBulk(array_column($installedMods, 'project_id'), $server) : [];

        $problems = Compatibility::problems(Compatibility::report($installedMods, $versions, $loaderKnown));

        if ($problems === []) {
            return [
                TextEntry::make('compatibility_all_compatible')
                    ->hiddenLabel()
                    ->state(trans('modrinth-plus::strings.compatibility.all_compatible', ['count' => count($installedMods)]))
                    ->icon('tabler-circle-check')
                    ->iconColor('success'),
            ];
        }

        $entries = [
            TextEntry::make('compatibility_summary')
                ->hiddenLabel()
                ->state(trans('modrinth-plus::strings.compatibility.summary', ['count' => count($problems), 'total' => count($installedMods)])),
        ];

        foreach ($problems as $index => $row) {
            $details = match ($row['status']) {
                Compatibility::NEEDS_UPDATE => trans('modrinth-plus::strings.compatibility.details.needs_update', [
                    'installed' => $row['installed_version'],
                    'newest' => $row['newest_version'] ?? trans('modrinth-plus::strings.page.unknown'),
                ]),
                Compatibility::INCOMPATIBLE => trans('modrinth-plus::strings.compatibility.details.incompatible', ['installed' => $row['installed_version']]),
                default => trans($loaderKnown ? 'modrinth-plus::strings.compatibility.details.unknown' : 'modrinth-plus::strings.compatibility.details.unknown_loader'),
            };

            // Titles come from the metadata file on the server: plain strings, so Blade escapes them.
            $entries[] = TextEntry::make('compatibility_' . $index)
                ->label($row['title'])
                ->state(trans('modrinth-plus::strings.compatibility.status.' . $row['status']))
                ->badge()
                ->color(match ($row['status']) {
                    Compatibility::NEEDS_UPDATE => 'warning',
                    Compatibility::INCOMPATIBLE => 'danger',
                    default => 'gray',
                })
                ->helperText($details);
        }

        return $entries;
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextEntry::make('Minecraft Version')
                            ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('modrinth-plus::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('Loader')
                            ->state(fn () => MinecraftModrinth::getLoaderFromServer($server)['display_name'] ?? trans('modrinth-plus::strings.page.unknown'))
                            ->icon(fn () => new HtmlString(MinecraftModrinth::getLoaderFromServer($server)['icon'] ?? ''))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(fn () => trans('modrinth-plus::strings.page.installed', ['type' => static::$modrinthProjectType?->getLabel() ?? 'Modrinth']))
                            ->state(function () use ($server) {
                                try {
                                    return collect(MinecraftModrinth::listFolder($server, static::$modrinthProjectType->getFolder()))
                                        ->filter(fn ($file) => ($file['mime'] ?? null) === 'application/jar' || str($file['name'] ?? '')->lower()->endsWith('.jar'))
                                        ->count();
                                } catch (Exception $exception) {
                                    report($exception);

                                    // Don't pass off an unreachable daemon as an empty folder.
                                    return trans('modrinth-plus::strings.page.unknown');
                                }
                            })
                            ->badge(),
                    ]),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }
}
