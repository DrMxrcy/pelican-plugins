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
 */
class BackupStep extends InstallStep
{
    public const KEY = 'backup';

    public const POLL_SECONDS = 10;

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
                } catch (TooManyBackupsException|TooManyRequestsHttpException|ModelNotFoundException $exception) {
                    $why = $exception instanceof ModelNotFoundException ? 'no backup host' : $exception->getMessage();

                    // A loader switch reinstalls the server: never without a backup.
                    if (!config('modpacks-plus.allow_skip_backup', false) || $install->isLoaderSwitch()) {
                        throw new StepFailedException('backup_impossible', $why, $exception);
                    }

                    $install->setProgress('backup_skipped', true);
                    $install->addLog('warning', 'backup_skipped', ['reason' => $why]);

                    return StepResult::skipped();
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
