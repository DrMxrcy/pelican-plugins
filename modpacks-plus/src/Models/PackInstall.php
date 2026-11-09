<?php

namespace DrMxrcy\ModpacksPlus\Models;

use App\Models\Server;
use App\Models\User;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
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
 * @property string|null $icon
 * @property string $status
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

        $preservedAside = $this->preservedEntries() !== [] && !$this->progressValue('preserved_restored', false);

        return $preservedAside || ($this->moved_aside_path !== null && $this->movedFolders() !== []);
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
