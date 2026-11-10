<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * A locked backup through Pelican's InitiateBackupService, never with `override`
 * (no old backup is ever deleted to make room). Its uuid is stored on the record and
 * only that backup is ever trusted. Waits (re-dispatching) until the daemon reports it
 * finished.
 *
 * Crash window (backup created, uuid not stored yet): before creating, a random nonce
 * is persisted on the record and put in the backup name. Recovery only accepts a
 * locked backup of this server, created no earlier than the install, whose name holds
 * that exact nonce, so a backup someone else made (same-looking name, older, unlocked)
 * can never stand in for the install's safety backup.
 *
 * Plan 46: the panel's backup throttle (TooManyRequestsHttpException, e.g. a version change
 * right before) is waited out until the step deadline; a full backup limit or no backup host
 * still ends the install at once (or skips the backup when the admin allows it).
 */
class BackupStep extends InstallStep
{
    public const KEY = 'backup';

    public const POLL_SECONDS = 10;

    /** Seconds added to the panel's Retry-After before trying again (plan 46). */
    public const THROTTLE_MARGIN_SECONDS = 5;

    /** Longest single wait for the backup throttle; the step deadline bounds the total. */
    public const MAX_THROTTLE_WAIT_SECONDS = 600;

    public static function backupName(PackInstall $install, string $nonce): string
    {
        return 'Modpacks Plus install #'.$install->id.' '.$nonce;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->progressValue('backup_skipped')) {
            return StepResult::skipped();
        }

        if ($install->backup_uuid === null) {
            $nonce = $install->progressValue('backup_nonce');
            $existing = is_string($nonce) ? $this->recoverBackup($install, $server, $nonce) : null;

            if ($existing === null) {
                // A fresh nonce per attempt, stored before the backup exists.
                $nonce = bin2hex(random_bytes(16));
                $install->setProgress('backup_nonce', $nonce);
                $engine->persist($install);

                try {
                    $existing = app(InitiateBackupService::class)
                        ->setIsLocked(true)
                        ->handle($server, self::backupName($install, $nonce));
                } catch (TooManyRequestsHttpException $exception) {
                    // Plan 46: the panel's backup throttle clears by itself; wait it out
                    // (one hop per window, no busy loop) while the step deadline allows.
                    $wait = self::throttleWait($exception);
                    $deadline = $install->step_deadline_at;
                    if ($deadline === null || now()->addSeconds($wait)->lt($deadline)) {
                        if (!$install->progressValue('backup_throttle_logged')) {
                            $install->setProgress('backup_throttle_logged', true);
                            $install->addLog('warning', 'waiting_backup_throttle', ['seconds' => $wait]);
                        }

                        return StepResult::again($wait);
                    }

                    return $this->impossible($install, $exception->getMessage(), $exception);
                } catch (TooManyBackupsException|ModelNotFoundException $exception) {
                    $why = $exception instanceof ModelNotFoundException ? 'no backup host' : $exception->getMessage();

                    return $this->impossible($install, $why, $exception);
                }
            }

            $install->backup_uuid = $existing->uuid;
            $install->addLog('info', 'backup_started', ['uuid' => $existing->uuid]);
            $engine->persist($install);

            return StepResult::again(self::POLL_SECONDS);
        }

        $backup = Backup::query()->where('server_id', $server->id)->where('uuid', $install->backup_uuid)->first();
        if ($backup === null) {
            throw new StepFailedException('backup_missing', $install->backup_uuid);
        }

        if ($backup->completed_at === null) {
            return StepResult::again(self::POLL_SECONDS);
        }

        if (!$backup->is_successful) {
            throw new StepFailedException('backup_failed', $backup->uuid);
        }

        $install->addLog('info', 'backup_done', ['uuid' => $backup->uuid]);

        return StepResult::done();
    }

    /**
     * No backup can be made: the install fails, or (admin setting, never for a loader switch)
     * goes on without one.
     *
     * @throws StepFailedException
     */
    private function impossible(PackInstall $install, string $why, Throwable $exception): StepResult
    {
        // A loader switch reinstalls the server: never without a backup.
        if (!config('modpacks-plus.allow_skip_backup', false) || $install->isLoaderSwitch()) {
            throw new StepFailedException('backup_impossible', $why, $exception);
        }

        $install->setProgress('backup_skipped', true);
        $install->addLog('warning', 'backup_skipped', ['reason' => $why]);

        return StepResult::skipped();
    }

    /**
     * Seconds until the throttle window has passed: the panel's Retry-After (plus a small
     * margin), or one throttle period when it gives none; never shorter than a poll.
     */
    private static function throttleWait(TooManyRequestsHttpException $exception): int
    {
        $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter + self::THROTTLE_MARGIN_SECONDS : (int) config('backups.throttles.period', 600);

        return max(self::POLL_SECONDS, min($seconds, self::MAX_THROTTLE_WAIT_SECONDS));
    }

    /** The backup this install created just before a crash, if any (see class doc). */
    private function recoverBackup(PackInstall $install, Server $server, string $nonce): ?Backup
    {
        $candidates = Backup::query()
            ->where('server_id', $server->id)
            ->where('name', self::backupName($install, $nonce))
            ->where('is_locked', true)
            ->where('created_at', '>=', $install->created_at)
            ->get();

        // Exactly one, or none: anything ambiguous is not trusted.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
