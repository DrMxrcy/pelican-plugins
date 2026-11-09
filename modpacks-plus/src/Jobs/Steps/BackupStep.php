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
 * the backup is named after the install, so a re-run reuses it instead of making a
 * second one. Waits (re-dispatching) until the daemon reports it finished.
 */
class BackupStep extends InstallStep
{
    public const KEY = 'backup';

    public const POLL_SECONDS = 10;

    public static function backupName(PackInstall $install): string
    {
        return 'Modpacks Plus install #'.$install->id;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->progressValue('backup_skipped')) {
            return StepResult::skipped();
        }

        if ($install->backup_uuid === null) {
            // A crash right after creating it: find it by name rather than making another.
            $existing = Backup::query()->where('server_id', $server->id)->where('name', self::backupName($install))->first();

            if ($existing === null) {
                try {
                    $existing = app(InitiateBackupService::class)
                        ->setIsLocked(true)
                        ->handle($server, self::backupName($install));
                } catch (TooManyBackupsException|TooManyRequestsHttpException|ModelNotFoundException $exception) {
                    $why = $exception instanceof ModelNotFoundException ? 'no backup host' : $exception->getMessage();

                    if (!config('modpacks-plus.allow_skip_backup', false)) {
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
}
