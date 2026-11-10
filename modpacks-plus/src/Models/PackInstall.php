<?php

namespace DrMxrcy\ModpacksPlus\Models;

use App\Models\Server;
use App\Models\User;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Services\InstallManifest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One pack install on one server: its status, step list, log and the validated plan.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $user_id
 * @property string $provider
 * @property string $pack_id
 * @property string $version_id
 * @property string $name
 * @property string|null $version_number
 * @property string|null $latest_version_id
 * @property string|null $latest_version_number
 * @property bool $update_available
 * @property string|null $notified_version
 * @property Carbon|null $update_checked_at
 * @property string|null $icon
 * @property string $status
 * @property string $mode
 * @property array<string, mixed>|null $options
 * @property int|null $previous_install_id
 * @property array<int, array{path: string, size: int, hash: string, kept: bool}>|null $manifest
 * @property string|null $current_step
 * @property int $seq
 * @property array<int, array{key: string, status: string, started_at: ?string, finished_at: ?string}>|null $steps
 * @property array<int, array{at: string, level: string, key: string, params: array<string, scalar>}>|null $log
 * @property array<string, mixed>|null $plan
 * @property array<string, mixed>|null $progress
 * @property array<string, mixed>|null $summary
 * @property string|null $backup_uuid
 * @property string|null $moved_aside_path
 * @property array<string, mixed>|null $loader_switch
 * @property array<string, mixed>|null $snapshot
 * @property string|null $preserved_path
 * @property Carbon|null $restore_requested_at
 * @property Carbon|null $restored_at
 * @property string|null $error
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $step_started_at
 * @property Carbon|null $step_deadline_at
 * @property Carbon|null $heartbeat_at
 * @property int $resumes
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Server|null $server
 * @property-read User|null $user
 */
class PackInstall extends Model
{
    public const STATUS_INSTALLING = 'installing';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** A pack onto a server with no recorded pack (or the same pack again). */
    public const MODE_INSTALL = 'install';

    /** Another pack replacing the installed one (plan 36). */
    public const MODE_SWITCH = 'switch';

    /** A newer version of the installed pack, applied as a diff (plan 36). */
    public const MODE_UPDATE = 'update';

    /** Another Minecraft version and/or loader without a pack (plan 40, the "Minecraft version" picker). */
    public const MODE_VERSION = 'version';

    /** The provider of a version change record (not a pack source: never in PackProviders::IDS). */
    public const VERSION_PROVIDER = 'minecraft';

    public const STEP_PENDING = 'pending';

    public const STEP_RUNNING = 'running';

    public const STEP_DONE = 'done';

    public const STEP_SKIPPED = 'skipped';

    public const STEP_FAILED = 'failed';

    public const STEP_CANCELLED = 'cancelled';

    /** Log lines kept per install (oldest dropped first). */
    public const MAX_LOG_LINES = 300;

    /** A "Restore previous files" that started this long ago without finishing may be asked for again. */
    public const RESTORE_MINUTES = 15;

    protected $table = 'mp_installs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'log' => 'array',
            'plan' => 'array',
            'manifest' => 'array',
            'options' => 'array',
            'previous_install_id' => 'integer',
            'progress' => 'array',
            'summary' => 'array',
            'loader_switch' => 'array',
            'snapshot' => 'array',
            'seq' => 'integer',
            'resumes' => 'integer',
            'cancel_requested_at' => 'datetime',
            'step_started_at' => 'datetime',
            'step_deadline_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'restore_requested_at' => 'datetime',
            'restored_at' => 'datetime',
            'update_available' => 'boolean',
            'update_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Installed pack records only: a version change (plan 40) never replaces the installed pack,
     * so every "installed pack" lookup (page, update checks, the next install's previous) skips it.
     *
     * @param  Builder<PackInstall>  $query
     */
    public function scopeInstalledPack(Builder $query): void
    {
        $query->where('status', self::STATUS_INSTALLED)->where('mode', '!=', self::MODE_VERSION);
    }

    /** The newest installed pack record of a server (never a version change). */
    public static function installedPack(int $serverId): ?self
    {
        return self::query()->where('server_id', $serverId)->installedPack()->latest('id')->first();
    }

    /** The newest version change (plan 40) that ended installed after this pack record, if any. */
    public function versionChangeSince(): ?self
    {
        return self::query()->where('server_id', $this->server_id)->where('status', self::STATUS_INSTALLED)
            ->where('mode', self::MODE_VERSION)->where('id', '>', $this->id)->latest('id')->first();
    }

    /** @param  Builder<PackInstall>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_INSTALLING);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }

    /** Whether this install moves the server to another loader egg first. */
    public function isLoaderSwitch(): bool
    {
        return is_array($this->loader_switch) && $this->loader_switch !== [];
    }

    /** Whether this is a pack-less change of the Minecraft version and/or loader (plan 40). */
    public function isVersionChange(): bool
    {
        return $this->mode === self::MODE_VERSION && $this->isLoaderSwitch();
    }

    /** Whether a restore of this install's files is running (asked for recently, not finished). */
    public function isRestoring(): bool
    {
        return $this->restored_at === null
            && $this->restore_requested_at !== null
            && $this->restore_requested_at->gt(now()->subMinutes(self::RESTORE_MINUTES));
    }

    /**
     * Whether "Restore previous files" applies: the install ended without installing, and
     * worlds/player files or old pack folders are still set aside.
     */
    public function isRestorable(): bool
    {
        if ($this->isActive() || $this->status === self::STATUS_INSTALLED || $this->restored_at !== null) {
            return false;
        }

        $preservedAside = $this->preserved_path !== null && !$this->progressValue('preserved_restored', false);

        return $preservedAside || ($this->moved_aside_path !== null && $this->movedFolders() !== []);
    }

    /** Whether worlds or player files of this ended install are still set aside. */
    public function hasPreservedPending(): bool
    {
        return !$this->isActive()
            && $this->preserved_path !== null
            && $this->restored_at === null
            && !$this->progressValue('preserved_restored', false);
    }

    /**
     * The install "Restore previous files" works on for a server: the newest one whose
     * worlds or player files are still set aside, else the latest install when restorable.
     */
    public static function restoreCandidate(int $serverId): ?self
    {
        $pending = self::query()->where('server_id', $serverId)->whereNotNull('preserved_path')->whereNull('restored_at')
            ->orderByDesc('id')->get()->first(fn (self $install) => $install->hasPreservedPending());
        if ($pending !== null) {
            return $pending;
        }

        $latest = self::query()->where('server_id', $serverId)->latest('id')->first();

        return $latest?->isRestorable() ? $latest : null;
    }

    /** @return array<int, string> worlds and player files moved into preserved_path */
    public function preservedEntries(): array
    {
        $entries = $this->progressValue('preserved', []);

        return $this->preserved_path !== null && is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];
    }

    /** @return array<int, string> old pack folders moved into moved_aside_path */
    public function movedFolders(): array
    {
        $moved = $this->progressValue('moved', []);

        return is_array($moved) ? array_values(array_filter($moved, 'is_string')) : [];
    }

    /**
     * The installed-files manifest, re-checked and keyed by path; null when none was recorded
     * (installs from before plan 36, or one whose manifest could not be built).
     *
     * @return array<string, array{path: string, size: int, hash: string, kept: bool}>|null
     */
    public function manifestEntries(): ?array
    {
        return InstallManifest::entries($this->manifest);
    }

    /** One of the admin's update/switch choices (fresh_world, overwrite_changes). */
    public function option(string $key): bool
    {
        return (bool) (($this->options ?? [])[$key] ?? false);
    }

    public function installPlan(): ?InstallPlan
    {
        return is_array($this->plan) ? InstallPlan::fromArray($this->plan) : null;
    }

    public function progressValue(string $key, mixed $default = null): mixed
    {
        return ($this->progress ?? [])[$key] ?? $default;
    }

    public function setProgress(string $key, mixed $value): void
    {
        $progress = $this->progress ?? [];
        $progress[$key] = $value;
        $this->progress = $progress;
    }

    /**
     * Appends a log line. Messages are translation keys (modpacks-plus::strings.log.<key>)
     * with scalar params, so the log renders in the viewer's language.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function addLog(string $level, string $key, array $params = []): void
    {
        $log = $this->log ?? [];
        $log[] = [
            'at' => now()->toIso8601String(),
            'level' => $level,
            'key' => $key,
            'params' => array_map(fn ($value) => is_string($value) ? mb_strimwidth($value, 0, 300, '…') : $value, $params),
        ];

        $this->log = array_slice($log, -self::MAX_LOG_LINES);
    }

    public function setStepStatus(string $key, string $status): void
    {
        $steps = $this->steps ?? [];
        foreach ($steps as $index => $step) {
            if ($step['key'] !== $key) {
                continue;
            }

            $steps[$index]['status'] = $status;
            if ($status === self::STEP_RUNNING && $step['started_at'] === null) {
                $steps[$index]['started_at'] = now()->toIso8601String();
            }
            if (in_array($status, [self::STEP_DONE, self::STEP_SKIPPED, self::STEP_FAILED, self::STEP_CANCELLED], true)) {
                $steps[$index]['finished_at'] = now()->toIso8601String();
            }
        }

        $this->steps = $steps;
    }

    public function stepStatus(string $key): ?string
    {
        foreach ($this->steps ?? [] as $step) {
            if ($step['key'] === $key) {
                return $step['status'];
            }
        }

        return null;
    }
}
