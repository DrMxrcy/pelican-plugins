<?php

namespace DrMxrcy\ModpacksPlus\Jobs;

use App\Enums\ServerState;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Jobs\Steps\RestoreLog;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\PreservedFiles;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Restore previous files" for an install that ended failed or cancelled: puts the
 * worlds and player files (preserved_path) and the old pack folders (moved_aside_path)
 * back where they were. Whatever is in their place now (a half-installed pack, files a
 * reinstall made) is moved to `.modpacks-plus/replaced-<date>-<id>/`: nothing is
 * overwritten or deleted. The server's egg is not switched back.
 *
 * Short and resumable like the install steps: a time budget, then it re-dispatches
 * itself; each entry is decided by what is on disk, so a retry just continues. It runs
 * only while the server is stopped and not installing, restoring a backup or
 * transferring; a server whose (re)install failed is allowed (that is the state to
 * recover from). Ends on its own after PackInstall::RESTORE_MINUTES.
 */
class RestorePreviousFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const BUDGET_SECONDS = 25;

    public const WAIT_SECONDS = 15;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    private float $startedAt = 0.0;

    final public function __construct(public readonly int $installId) {}

    /** Whether file moves must wait: suspended, node in maintenance, installing, restoring a backup or transferring. */
    public static function filesBlocked(Server $server): bool
    {
        return $server->isSuspended()
            || $server->node->isUnderMaintenance()
            || $server->status === ServerState::Installing
            || $server->status === ServerState::RestoringBackup
            || $server->transfer !== null;
    }

    public function handle(): void
    {
        $this->startedAt = microtime(true);

        $lock = Cache::lock("modpacks-plus:install:{$this->installId}", $this->timeout + 30);
        if (!$lock->get()) {
            dispatch(new static($this->installId))->delay(self::WAIT_SECONDS);

            return;
        }

        try {
            $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): void
    {
        $install = PackInstall::query()->find($this->installId);
        if ($install === null || $install->isActive() || $install->restored_at !== null || $install->restore_requested_at === null) {
            return;
        }

        if (!$install->isRestoring()) {
            $this->stop($install, 'restore_timeout', '');

            return;
        }

        $server = $install->server;
        if ($server === null) {
            $this->stop($install, 'restore_failed', 'server_missing');

            return;
        }

        if (PackInstall::query()->where('server_id', $server->id)->active()->exists()) {
            $this->stop($install, 'restore_failed', 'already_running');

            return;
        }

        if (self::filesBlocked($server)) {
            $this->waitOnce($install, 'restore_waiting', ['state' => (string) ($server->status->value ?? 'transfer')]);
            $this->again(self::WAIT_SECONDS);

            return;
        }

        $power = app(ServerPower::class);
        $state = $power->state($server);
        if (!in_array($state, ServerPower::STOPPED, true)) {
            if (!$install->progressValue('restore_stop_sent') && !in_array($state, ['missing', 'stopping'], true)) {
                $install->setProgress('restore_stop_sent', true);
                $install->addLog('info', 'stopping_server', ['state' => $state]);
                $install->save();
                $power->stop($server);
            } else {
                $this->waitOnce($install, 'waiting_stop', ['state' => $state]);
            }
            $this->again(self::WAIT_SECONDS);

            return;
        }

        try {
            $this->restore($install, $server);
        } catch (StepFailedException|UnsafePlanException $exception) {
            $this->stop($install, 'restore_failed', $exception->reason.($exception->detail !== '' ? ': '.$exception->detail : ''));
        }
    }

    /** @throws StepFailedException|UnsafePlanException */
    private function restore(PackInstall $install, Server $server): void
    {
        $replaced = $install->progressValue('restore_replaced_path');
        if (!is_string($replaced)) {
            $replaced = PlanValidator::OWN_FOLDER.'/replaced-'.now()->format('Ymd-His').'-'.$install->id;
            $install->setProgress('restore_replaced_path', $replaced);
            $install->save();
        }

        $worlds = $install->progressValue('worlds', ['world']);
        $files = app(ServerFiles::class)->protecting(is_array($worlds) ? array_values(array_filter($worlds, 'is_string')) : []);
        $service = new PreservedFiles();

        /** @var array<int, array{string, string, string}> $todo [key, root, entry] */
        $todo = [];
        if (!$install->progressValue('preserved_restored')) {
            foreach ($install->preservedEntries() as $entry) {
                $todo[] = ['p:'.$entry, (string) $install->preserved_path, $entry];
            }
        }
        if ($install->moved_aside_path !== null) {
            foreach ($install->movedFolders() as $folder) {
                $todo[] = ['m:'.$folder, $install->moved_aside_path, $folder];
            }
        }

        $done = $install->progressValue('restore_done', []);
        foreach ($todo as [$key, $root, $entry]) {
            if (in_array($key, $done, true)) {
                continue;
            }
            if (microtime(true) - $this->startedAt > self::BUDGET_SECONDS) {
                $this->again(0);

                return;
            }

            $result = $service->restore($files, $server, $root, $entry, $replaced);
            RestoreLog::add($install, $result, $entry, $replaced);

            $done[] = $key;
            $install->setProgress('restore_done', $done);
            $install->save();
        }

        $install->restored_at = now();
        $install->addLog('info', 'restore_finished', ['count' => count($done)]);
        $install->save();
    }

    /** @param  array<string, scalar>  $params */
    private function waitOnce(PackInstall $install, string $key, array $params): void
    {
        if (!$install->progressValue('restore_logged_'.$key)) {
            $install->setProgress('restore_logged_'.$key, true);
            $install->addLog('warning', $key, $params);
            $install->save();
        }
    }

    /** Ends this restore attempt; the files stay where they are and it can be asked for again. */
    private function stop(PackInstall $install, string $key, string $detail): void
    {
        $install->addLog('error', $key, ['detail' => $detail]);
        $install->restore_requested_at = null;
        $install->save();
    }

    private function again(int $delay): void
    {
        $job = new static($this->installId);
        if ($delay > 0) {
            $job->delay($delay);
        }
        dispatch($job);
    }

    /** Queue gave up after its retries: end this attempt so it can be asked for again. */
    public function failed(?Throwable $exception): void
    {
        $install = PackInstall::query()->find($this->installId);
        if ($install !== null && $install->restored_at === null && $install->restore_requested_at !== null) {
            $this->stop($install, 'restore_failed', $exception ? class_basename($exception) : '');
        }
    }
}
