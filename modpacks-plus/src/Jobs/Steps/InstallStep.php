<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StaleJobException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
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

    /** Seconds of work per job before handing over to a fresh one (jobs stay well under ~60 s). */
    public const BUDGET_SECONDS = 40;

    /** Whether this step touches the server's files (and so waits out conflict states). */
    protected const TOUCHES_FILES = true;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    private float $startedAt = 0.0;

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

        try {
            $this->run($engine);
        } finally {
            $lock->release();
        }
    }

    private function run(InstallEngine $engine): void
    {
        $install = PackInstall::query()->find($this->installId);
        if ($install === null || !$install->isActive() || $install->seq !== $this->seq || $install->current_step !== static::KEY) {
            return;
        }

        if ($install->cancel_requested_at !== null) {
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
            $engine->fail($install, $exception->reason, $exception->detail, $this->seq);
        }
    }

    private function advance(PackInstall $install, InstallEngine $engine, bool $skipped): void
    {
        $install->setStepStatus(static::KEY, $skipped ? PackInstall::STEP_SKIPPED : PackInstall::STEP_DONE);

        $next = InstallEngine::nextStep(static::KEY);
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
        return microtime(true) - $this->startedAt > static::BUDGET_SECONDS;
    }

    protected function files(): ServerFiles
    {
        return app(ServerFiles::class);
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
