<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Server;
use App\Models\User;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Exceptions\StaleJobException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Jobs\Steps\BackupStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\FinalizeStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\MoveAsideStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\OverridesStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PrepareStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Runs a pack install as a chain of short queue jobs (one per step hop).
 *
 * Single chain guarantee: every hop bumps the record's `seq` under a row lock and the
 * next job carries the new value; a job whose seq is no longer current (a queue retry
 * after a crash, a duplicate) does nothing. Every state change of a running job goes
 * through persist(), which refuses when the seq moved on.
 *
 * Never stuck: each step has a deadline, and the watchdog (scheduled every minute)
 * fails installs past it, resumes chains whose heartbeat stalled (lost job) up to
 * MAX_RESUMES times, then fails them.
 */
class InstallEngine
{
    /** Step key => job class, in run order. */
    public const STEPS = [
        PrepareStep::KEY => PrepareStep::class,
        BackupStep::KEY => BackupStep::class,
        MoveAsideStep::KEY => MoveAsideStep::class,
        DownloadStep::KEY => DownloadStep::class,
        OverridesStep::KEY => OverridesStep::class,
        FinalizeStep::KEY => FinalizeStep::class,
    ];

    /** Minutes each step may take in total, waits and resumes included. */
    public const DEADLINES = [
        PrepareStep::KEY => 15,
        BackupStep::KEY => 60,
        MoveAsideStep::KEY => 15,
        DownloadStep::KEY => 120,
        OverridesStep::KEY => 60,
        FinalizeStep::KEY => 10,
    ];

    /** A chain whose heartbeat is older than this is considered lost and resumed. */
    public const STALL_MINUTES = 5;

    public const MAX_RESUMES = 3;

    /**
     * Creates the install record and queues its first step.
     *
     * @throws InstallRefusedException
     */
    public function start(Server $server, ?User $user, string $provider, string $packId, string $versionId, string $name, ?string $versionNumber = null, ?string $icon = null): PackInstall
    {
        // Waits re-dispatch themselves; the sync queue would run them inline, forever.
        if (config('queue.default') === 'sync') {
            throw new InstallRefusedException('queue_required');
        }

        $lock = Cache::lock("modpacks-plus:server:{$server->id}", 10);
        if (!$lock->get()) {
            throw new InstallRefusedException('already_running');
        }

        try {
            if (PackInstall::query()->where('server_id', $server->id)->active()->exists()) {
                throw new InstallRefusedException('already_running');
            }

            $first = array_key_first(self::STEPS);
            $install = PackInstall::query()->create([
                'server_id' => $server->id,
                'user_id' => $user?->id,
                'provider' => $provider,
                'pack_id' => $packId,
                'version_id' => $versionId,
                'name' => mb_strimwidth($name, 0, 250, '…'),
                'version_number' => $versionNumber !== null ? mb_strimwidth($versionNumber, 0, 250, '…') : null,
                'icon' => $icon !== null && str_starts_with($icon, 'https://') ? $icon : null,
                'status' => PackInstall::STATUS_INSTALLING,
                'current_step' => $first,
                'seq' => 1,
                'steps' => array_map(fn (string $key) => ['key' => $key, 'status' => PackInstall::STEP_PENDING, 'started_at' => null, 'finished_at' => null], array_keys(self::STEPS)),
                'log' => [],
                'progress' => [],
                'step_started_at' => now(),
                'step_deadline_at' => now()->addMinutes(self::DEADLINES[$first]),
                'heartbeat_at' => now(),
            ]);

            $install->addLog('info', 'started', ['name' => $install->name, 'version' => $install->version_number ?? $versionId]);
            $install->save();
        } finally {
            $lock->release();
        }

        $this->dispatch($install);

        return $install;
    }

    /** Asks a running install to stop; the next step job (or the watchdog) acts on it. */
    public function requestCancel(PackInstall $install): void
    {
        if (!$install->isActive() || $install->cancel_requested_at !== null) {
            return;
        }

        PackInstall::query()->whereKey($install->id)->whereNull('cancel_requested_at')->update(['cancel_requested_at' => now()]);
        $install->refresh();
    }

    /**
     * Moves the chain to $step (or stays on it to wait) and queues the next job. Returns
     * false when this job is no longer the current one.
     */
    public function hop(PackInstall $install, string $step, int $delaySeconds = 0): bool
    {
        $current = $install->current_step;
        if ($step !== $current) {
            $install->current_step = $step;
            $install->step_started_at = now();
            $install->step_deadline_at = now()->addMinutes(self::DEADLINES[$step] ?? 15);
            $install->setStepStatus($step, PackInstall::STEP_RUNNING);
        }
        $install->heartbeat_at = now();

        try {
            $this->persist($install, bump: true);
        } catch (StaleJobException) {
            return false;
        }

        $this->dispatch($install, $delaySeconds);

        return true;
    }

    /** The step after $step, or null after the last one. */
    public static function nextStep(string $step): ?string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);

        return $index === false ? null : ($keys[$index + 1] ?? null);
    }

    /**
     * Saves a running job's changes if (and only if) the record is still at the seq the
     * job was given and still installing; optionally bumps the seq for the next hop.
     *
     * @throws StaleJobException
     */
    public function persist(PackInstall $install, bool $bump = false): void
    {
        DB::transaction(function () use ($install, $bump) {
            $locked = PackInstall::query()->whereKey($install->id)->lockForUpdate()->first(['id', 'seq', 'status', 'cancel_requested_at']);

            if ($locked === null || $locked->seq !== $install->getOriginal('seq') || !$locked->isActive()) {
                throw new StaleJobException();
            }

            if ($bump) {
                $install->seq = $locked->seq + 1;
            }

            $install->save();
        });
    }

    /** @param  int|null  $expectedSeq  when given, only ends the install if it is still at this seq */
    public function fail(PackInstall $install, string $reason, string $detail = '', ?int $expectedSeq = null): void
    {
        $this->finish($install, $expectedSeq, PackInstall::STATUS_FAILED, PackInstall::STEP_FAILED, function (PackInstall $install) use ($reason, $detail) {
            $install->error = $reason;
            $install->setProgress('error_detail', $detail);
            $install->addLog('error', 'failed', ['reason' => $reason, 'detail' => $detail]);
        });
    }

    public function cancel(PackInstall $install, ?int $expectedSeq = null): void
    {
        $this->finish($install, $expectedSeq, PackInstall::STATUS_CANCELLED, PackInstall::STEP_CANCELLED, function (PackInstall $install) {
            $install->addLog('warning', 'cancelled', ['step' => (string) $install->current_step]);
        });
    }

    public function complete(PackInstall $install, ?int $expectedSeq = null): void
    {
        $this->finish($install, $expectedSeq, PackInstall::STATUS_INSTALLED, PackInstall::STEP_DONE, function (PackInstall $install) {
            $install->addLog('info', 'installed', ['name' => $install->name]);
        });
    }

    /** Ends an install (any state change is skipped when it already ended). */
    private function finish(PackInstall $install, ?int $expectedSeq, string $status, string $stepStatus, \Closure $apply): void
    {
        $ended = DB::transaction(function () use ($install, $expectedSeq, $status, $stepStatus, $apply) {
            $fresh = PackInstall::query()->whereKey($install->id)->lockForUpdate()->first();
            if ($fresh === null || !$fresh->isActive() || ($expectedSeq !== null && $fresh->seq !== $expectedSeq)) {
                return false;
            }

            // Keep in-memory progress/log of the job that ends it, on top of the fresh row.
            foreach (['progress', 'log', 'steps', 'plan', 'backup_uuid', 'moved_aside_path'] as $attribute) {
                if ($install->isDirty($attribute)) {
                    $fresh->setAttribute($attribute, $install->getAttribute($attribute));
                }
            }

            if ($fresh->current_step !== null) {
                $fresh->setStepStatus($fresh->current_step, $stepStatus);
            }
            $apply($fresh);
            $fresh->status = $status;
            $fresh->seq = $fresh->seq + 1;
            $fresh->finished_at = now();
            $fresh->summary = $this->summary($fresh);
            $fresh->save();

            $install->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if ($ended) {
            File::deleteDirectory($this->workDir($install));
        }
    }

    /**
     * What happened, for the final summary.
     *
     * @return array<string, mixed>
     */
    public function summary(PackInstall $install): array
    {
        $plan = $install->installPlan();

        return [
            'backup_uuid' => $install->backup_uuid,
            'backup_skipped' => (bool) $install->progressValue('backup_skipped', false),
            'moved_aside_path' => $install->moved_aside_path,
            'moved' => $install->progressValue('moved', []),
            'downloaded' => (int) $install->progressValue('download_index', 0),
            'files_total' => $plan ? count($plan->files) : null,
            'overrides_applied' => (int) $install->progressValue('override_index', 0),
            'overrides_total' => $plan ? count($plan->overrides) : null,
            'skipped_client_files' => $plan ? count($plan->skippedClientFiles) : 0,
            'skipped_overrides' => $plan ? $plan->skippedOverrides : [],
        ];
    }

    /**
     * Fails installs past their step deadline and resumes (or, after MAX_RESUMES, fails)
     * chains whose heartbeat stalled. Returns [failed, resumed].
     *
     * @return array{int, int}
     */
    public function watchdog(): array
    {
        $failed = 0;
        $resumed = 0;

        foreach (PackInstall::query()->active()->get() as $install) {
            $stalled = $install->heartbeat_at === null || $install->heartbeat_at->lt(now()->subMinutes(self::STALL_MINUTES));

            if ($install->step_deadline_at !== null && $install->step_deadline_at->isPast()) {
                $this->fail($install, 'step_timeout', (string) $install->current_step);
                $failed++;
            } elseif ($stalled && $install->cancel_requested_at !== null) {
                $this->cancel($install);
                $failed++;
            } elseif ($stalled && $install->resumes >= self::MAX_RESUMES) {
                $this->fail($install, 'stalled', (string) $install->current_step);
                $failed++;
            } elseif ($stalled) {
                $install->resumes++;
                $install->addLog('warning', 'resumed', ['step' => (string) $install->current_step]);
                if ($this->hop($install, (string) $install->current_step)) {
                    $resumed++;
                }
            }
        }

        return [$failed, $resumed];
    }

    /** Panel-side scratch folder for this install (the downloaded archive). */
    public function workDir(PackInstall $install): string
    {
        return storage_path('app/private/modpacks-plus/installs/'.$install->id);
    }

    /**
     * The validator for this install, protecting the worlds found while preparing.
     *
     * @throws UnsafePlanException
     */
    public function validatorFor(PackInstall $install): PlanValidator
    {
        $worlds = $install->progressValue('worlds');

        return new PlanValidator(Caps::fromConfig(), is_array($worlds) ? array_values(array_filter($worlds, 'is_string')) : ['world']);
    }

    private function dispatch(PackInstall $install, int $delaySeconds = 0): void
    {
        $class = self::STEPS[$install->current_step] ?? null;
        if ($class === null) {
            return;
        }
        $job = new $class($install->id, $install->seq);
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        dispatch($job);
    }
}
