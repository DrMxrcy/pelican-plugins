<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StaleJobException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One hop of an install chain. Every run first re-reads the record and does nothing
 * unless it is still the current job (same seq, same step, still installing), so queue
 * retries (`--tries=3`) and duplicates are harmless. Then, in order: cancel, deadline,
 * server conflict state, and only then the step's own work, which is idempotent.
 */
abstract class InstallStep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const KEY = '';

    /**
     * Seconds of work per job before handing over to a fresh one. Budget plus one fetch
     * (DownloadStep::PULL_TIMEOUT / ModrinthClient::DOWNLOAD_TIMEOUT, 30 s) stays under
     * ~60 s, below the queue's default retry_after of 90 s.
     */
    public const BUDGET_SECONDS = 25;

    /** Fresh jobs a step gets after running out of time mid-request before the install fails. */
    public const MAX_TIME_RETRIES = 5;

    /** Whether this step touches the server's files (and so waits out conflict states). */
    protected const TOUCHES_FILES = true;

    /** Seconds between checks while waiting for the server to stop. */
    public const STOP_POLL_SECONDS = 10;

    /**
     * Whether a cancel request ends the install at this step. A loader switch is not
     * cancellable from the moment worlds start moving aside until they are back: a cancel
     * asked for then waits for the first cancellable step after the restore.
     */
    public static function cancellableAt(PackInstall $install): bool
    {
        return true;
    }

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    private float $startedAt = 0.0;

    /** The install this run works on (gives files() the worlds to protect). */
    private ?PackInstall $current = null;

    final public function __construct(public readonly int $installId, public readonly int $seq) {}

    abstract protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult;

    public function handle(InstallEngine $engine): void
    {
        $this->startedAt = microtime(true);

        $lock = Cache::lock("modpacks-plus:install:{$this->installId}", $this->timeout + 30);
        if (!$lock->get()) {
            // Another job of this install is running; try again shortly (seq decides who wins).
            dispatch(new static($this->installId, $this->seq))->delay(15);

            return;
        }

        // Every request this job makes gets at most the time it has left (plan 32 security review).
        JobClock::start(PanelDownload::JOB_SECONDS);
        try {
            $this->run($engine);
        } finally {
            JobClock::stop();
            $lock->release();
        }
    }

    private function run(InstallEngine $engine): void
    {
        $install = PackInstall::query()->find($this->installId);
        if ($install === null || !$install->isActive() || $install->seq !== $this->seq || $install->current_step !== static::KEY) {
            return;
        }

        if ($install->cancel_requested_at !== null && static::cancellableAt($install)) {
            $engine->cancel($install, $this->seq);

            return;
        }

        if ($install->step_deadline_at !== null && $install->step_deadline_at->isPast()) {
            $engine->fail($install, 'step_timeout', static::KEY, $this->seq);

            return;
        }

        $server = $install->server;
        if ($server === null) {
            $engine->fail($install, 'server_missing', '', $this->seq);

            return;
        }

        $this->current = $install;

        try {
            $install->heartbeat_at = now();
            $install->setStepStatus(static::KEY, PackInstall::STEP_RUNNING);

            if (static::TOUCHES_FILES && $server->isInConflictState()) {
                if (!$install->progressValue('conflict_logged_'.static::KEY)) {
                    $install->setProgress('conflict_logged_'.static::KEY, true);
                    $install->addLog('warning', 'waiting_conflict', ['step' => static::KEY]);
                }
                $engine->hop($install, static::KEY, 30);

                return;
            }

            $engine->persist($install);

            $result = $this->perform($install, $server, $engine);

            match ($result->kind) {
                'done', 'skipped' => $this->advance($install, $engine, $result->kind === 'skipped'),
                'again' => $engine->hop($install, static::KEY, $result->delay),
                default => null,
            };
        } catch (StaleJobException) {
            return;
        } catch (UnsafePlanException|PackUnavailableException|StepFailedException $exception) {
            // A source that failed because this job ran out of time gets a fresh job (bounded).
            if ($exception instanceof PackUnavailableException && JobClock::exhausted() && $this->retryForTime($install, $engine)) {
                return;
            }
            $engine->fail($install, $exception->reason, $exception->detail, $this->seq);
        }
    }

    /** Hands the step to a fresh job after it ran out of time, at most MAX_TIME_RETRIES times. */
    private function retryForTime(PackInstall $install, InstallEngine $engine): bool
    {
        $key = 'time_retries_'.static::KEY;
        $retries = (int) $install->progressValue($key, 0);
        if ($retries >= self::MAX_TIME_RETRIES) {
            return false;
        }

        $install->setProgress($key, $retries + 1);
        $install->addLog('warning', 'time_retry', ['step' => static::KEY]);

        return $engine->hop($install, static::KEY);
    }

    private function advance(PackInstall $install, InstallEngine $engine, bool $skipped): void
    {
        $install->setStepStatus(static::KEY, $skipped ? PackInstall::STEP_SKIPPED : PackInstall::STEP_DONE);

        $next = InstallEngine::nextStepOf($install, static::KEY);
        if ($next === null) {
            $engine->complete($install, $this->seq);

            return;
        }

        $engine->hop($install, $next);
    }

    /** Queue gave up after its retries: end the install instead of leaving it running. */
    public function failed(?Throwable $exception): void
    {
        $install = PackInstall::query()->find($this->installId);
        if ($install !== null) {
            app(InstallEngine::class)->fail($install, 'unexpected', $exception ? class_basename($exception) : '', $this->seq);
        }
    }

    /** Whether this job used up its time budget and should hand over. */
    protected function outOfTime(): bool
    {
        return $this->elapsedSeconds() > static::BUDGET_SECONDS;
    }

    /** Seconds this job has been running. */
    protected function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /** The server file operations, also refusing changes to this install's world folders. */
    protected function files(): ServerFiles
    {
        $worlds = $this->current?->progressValue('worlds');

        return app(ServerFiles::class)->protecting(is_array($worlds) ? array_values(array_filter($worlds, 'is_string')) : []);
    }

    /**
     * True when the server process is stopped. Otherwise asks the node to stop it (once per
     * step) and returns false: the caller waits (StepResult::again) and checks again. An
     * unreachable node (`missing`) is waited out too; the step deadline bounds the wait.
     */
    protected function ensureStopped(PackInstall $install, Server $server, InstallEngine $engine): bool
    {
        $power = app(ServerPower::class);
        $state = $power->state($server);
        if (in_array($state, ServerPower::STOPPED, true)) {
            return true;
        }

        $key = 'stop_sent_'.static::KEY;
        if (!$install->progressValue($key) && !in_array($state, ['missing', 'stopping'], true)) {
            $install->setProgress($key, true);
            $install->addLog('info', 'stopping_server', ['state' => $state]);
            $engine->persist($install);
            $power->stop($server);
        } elseif (!$install->progressValue('stop_wait_logged_'.static::KEY)) {
            $install->setProgress('stop_wait_logged_'.static::KEY, true);
            $install->addLog('warning', 'waiting_stop', ['state' => $state]);
        }

        return false;
    }

    /** Creates a directory once per install (remembered in progress.dirs). */
    protected function ensureDirectory(PackInstall $install, Server $server, string $directory): void
    {
        if ($directory === '') {
            return;
        }

        $known = $install->progressValue('dirs', []);
        if (in_array($directory, $known, true)) {
            return;
        }

        $this->files()->createDirectory($server, $directory);
        $known[] = $directory;
        $install->setProgress('dirs', $known);
    }

    /**
     * A file the install did not write is in the way: move it into the moved-aside
     * folder instead of overwriting it (old files are never deleted silently).
     *
     * @throws StepFailedException
     */
    protected function moveExistingAside(PackInstall $install, Server $server, string $path): void
    {
        $aside = $install->moved_aside_path;
        if ($aside === null) {
            throw new StepFailedException('move_aside_conflict', $path);
        }

        $target = "$aside/$path";
        $directory = dirname($target);
        $this->files()->createDirectory($server, $directory);

        if ($this->files()->stat($server, $target) !== null) {
            throw new StepFailedException('move_aside_conflict', $target);
        }

        $this->files()->rename($server, $path, $target);
        $install->addLog('info', 'moved_aside', ['path' => $path, 'to' => $target]);
    }
}
