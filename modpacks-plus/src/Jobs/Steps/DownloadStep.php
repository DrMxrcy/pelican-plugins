<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Has the node pull each server-side file, in batches, then verifies it:
 * - VERIFY_MODRINTH files: hash vouched for by Modrinth's lookup while preparing; the
 *   size is checked here;
 * - VERIFY_READBACK files: read back through the panel and hashed (sha512, else sha1).
 * A mismatch deletes the file and fails the install.
 *
 * Idempotent: the cursor (progress.download_index) only moves after a file is verified,
 * and the attempt is recorded before each pull, so after a crash a file that already
 * landed is verified instead of downloaded again. Cancel is honoured between batches.
 */
class DownloadStep extends InstallStep
{
    public const KEY = 'download';

    /** Seconds the panel waits for one foreground pull. */
    public const PULL_TIMEOUT = 50;

    public const MAX_ATTEMPTS = 3;

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        // Re-checked on every run: the stored plan is only trusted after validation.
        $plan = $engine->validatorFor($install)->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));
        $caps = Caps::fromConfig();
        $batch = max(1, (int) config('modpacks-plus.download_batch', 10));

        $index = (int) $install->progressValue('download_index', 0);
        $done = 0;

        while ($index < count($plan->files)) {
            if ($done >= $batch || $this->outOfTime()) {
                // Hand over to a fresh job; cancel is checked before it continues.
                return StepResult::again();
            }

            $result = $this->downloadOne($install, $server, $engine, $plan->files[$index], $index, $caps);
            if ($result !== null) {
                return $result;
            }

            $index++;
            $done++;
            $install->setProgress('download_index', $index);
            $install->setProgress('pending_pull', null);
            $engine->persist($install);
        }

        $install->addLog('info', 'downloaded', ['count' => count($plan->files)]);

        return StepResult::done();
    }

    /** Null when the file is in place and verified; a result to return otherwise. */
    private function downloadOne(PackInstall $install, Server $server, InstallEngine $engine, PlanFile $file, int $index, Caps $caps): ?StepResult
    {
        $files = $this->files();
        $pending = $install->progressValue('pending_pull');
        $ours = is_array($pending) && ($pending['index'] ?? null) === $index;
        $attempts = $ours ? (int) ($pending['attempts'] ?? 0) : 0;

        $stat = $files->stat($server, $file->path);
        if ($stat !== null) {
            if ($stat['directory']) {
                throw new StepFailedException('path_conflict', $file->path);
            }

            if ($ours && $stat['size'] === $file->size) {
                // Landed before a crash or a timed-out request: verify, don't download again.
                $this->verify($server, $file, $caps);
                $install->addLog('info', 'file_verified', ['path' => $file->path]);

                return null;
            }

            if ($ours) {
                $files->delete($server, $file->path);
            } else {
                $this->moveExistingAside($install, $server, $file->path);
            }
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new StepFailedException('download_failed', $file->path);
        }

        $this->ensureDirectory($install, $server, $file->directory());
        $install->setProgress('pending_pull', ['index' => $index, 'attempts' => $attempts + 1]);
        $engine->persist($install);

        try {
            $files->pull($server, (string) $file->url, $file->directory(), $file->filename(), self::PULL_TIMEOUT);
        } catch (ConnectionException) {
            // The node may still be downloading; check for it on the next run.
            return StepResult::again(20);
        } catch (RequestException $exception) {
            $install->addLog('warning', 'pull_retry', ['path' => $file->path, 'status' => $exception->response->status()]);

            return StepResult::again(10);
        }

        $stat = $files->stat($server, $file->path);
        if ($stat === null || $stat['directory'] || $stat['size'] !== $file->size) {
            if ($stat !== null && !$stat['directory']) {
                $files->delete($server, $file->path);
            }

            throw new StepFailedException('download_incomplete', $file->path);
        }

        $this->verify($server, $file, $caps);
        $install->addLog('info', 'file_downloaded', ['path' => $file->path, 'verify' => (string) $file->verify]);

        return null;
    }

    /** @throws UnsafePlanException */
    private function verify(Server $server, PlanFile $file, Caps $caps): void
    {
        if ($file->verify === PlanFile::VERIFY_MODRINTH) {
            return;
        }

        if ($file->verify !== PlanFile::VERIFY_READBACK) {
            throw new UnsafePlanException('bad_hash', $file->path);
        }

        try {
            PlanValidator::assertContentMatches($file, $this->files()->read($server, $file->path, $caps->maxReadbackBytes));
        } catch (UnsafePlanException $exception) {
            $this->files()->delete($server, $file->path);

            throw $exception;
        }
    }
}
