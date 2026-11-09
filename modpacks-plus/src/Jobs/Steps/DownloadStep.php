<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Puts each server-side file in place, in batches:
 * - VERIFY_MODRINTH (cdn.modrinth.com, hash vouched for by Modrinth): the node pulls it;
 *   these are the only URLs the node ever gets, because it follows redirects on its own;
 * - VERIFY_PANEL (anything else): the panel streams it to a temp file (every redirect hop
 *   allowlisted, capped by max_panel_fetch_bytes while streaming, so hosts without
 *   Content-Length such as dist.modpacks.ch are capped too), checks size and sha512/sha1,
 *   then writes it (PanelDownload). A fetch only gets the time left in its job and only
 *   starts with enough left; a host that fails or times out is retried in a fresh job.
 *
 * Every file first lands in `.modpacks-plus/staging/<install>/` and is renamed into its
 * place only once verified, so unverified bytes never sit in `mods/`. A file the install
 * didn't write that is in the way is moved aside first.
 *
 * Idempotent: the attempt is recorded before fetching and the cursor moves only after
 * the rename, so after a crash a file already in place is kept instead of fetched again.
 * Cancel is honoured between batches.
 */
class DownloadStep extends InstallStep
{
    public const KEY = 'download';

    /** Seconds the panel waits for one foreground node pull; with BUDGET_SECONDS a job stays under ~60 s. */
    public const PULL_TIMEOUT = 30;

    public const MAX_ATTEMPTS = 3;

    public static function stagingRoot(PackInstall $install): string
    {
        return PlanValidator::OWN_FOLDER.'/staging/'.$install->id;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        // Re-checked on every run: the stored plan is only trusted after validation.
        $validator = $engine->validatorFor($install);
        $plan = $validator->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));
        $caps = Caps::fromConfig();
        $batch = max(1, (int) config('modpacks-plus.download_batch', 10));

        $index = (int) $install->progressValue('download_index', 0);
        $done = 0;

        while ($index < count($plan->files)) {
            if ($done >= $batch || $this->outOfTime()) {
                // Hand over to a fresh job; cancel is checked before it continues.
                return StepResult::again();
            }

            // Plan 32: a panel fetch only starts with time left in this job for it and the write.
            if (in_array($plan->files[$index]->verify, PlanFile::PANEL_FETCHED, true) && PanelDownload::timeout($this->elapsedSeconds()) === null) {
                return StepResult::again();
            }

            $result = $this->placeOne($install, $server, $engine, $validator, $plan->files[$index], $index, $caps);
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
    private function placeOne(PackInstall $install, Server $server, InstallEngine $engine, PlanValidator $validator, PlanFile $file, int $index, Caps $caps): ?StepResult
    {
        $files = $this->files();
        $pending = $install->progressValue('pending_pull');
        $ours = is_array($pending) && ($pending['index'] ?? null) === $index;
        $attempts = $ours ? (int) ($pending['attempts'] ?? 0) : 0;

        $final = $files->stat($server, $file->path);
        if ($final !== null && $final['directory']) {
            throw new StepFailedException('path_conflict', $file->path);
        }

        if ($final !== null && $ours) {
            // Only a verified file is ever renamed into place, and anything that was there
            // before this attempt was moved aside first: this is our file, landed before a crash.
            if ($final['size'] === $file->size) {
                $install->addLog('info', 'file_verified', ['path' => $file->path]);

                return null;
            }

            throw new StepFailedException('download_incomplete', $file->path);
        }

        if ($final !== null) {
            $this->moveExistingAside($install, $server, $file->path);
        }

        $staged = self::stagingRoot($install).'/'.$file->path;
        $stagedDirectory = dirname($staged);
        $stagedStat = $files->stat($server, $staged);

        if ($ours && $file->verify === PlanFile::VERIFY_MODRINTH && $stagedStat !== null && !$stagedStat['directory'] && $stagedStat['size'] === $file->size) {
            // A node pull whose request timed out but which finished: verify, don't pull again.
            $this->ensureDirectory($install, $server, $file->directory());
            $files->rename($server, $staged, $file->path);
            $install->addLog('info', 'file_verified', ['path' => $file->path]);

            return null;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new StepFailedException('download_failed', $file->path);
        }

        $install->setProgress('pending_pull', ['index' => $index, 'attempts' => $attempts + 1]);
        $engine->persist($install);

        $this->ensureDirectory($install, $server, $stagedDirectory);

        if ($stagedStat !== null) {
            // Left from an attempt that never got verified.
            $files->delete($server, $staged);
        }

        if ($file->verify === PlanFile::VERIFY_MODRINTH) {
            try {
                $files->pull($server, (string) $file->url, $stagedDirectory, basename($staged), self::PULL_TIMEOUT);
            } catch (ConnectionException) {
                // The node may still be downloading; the staged file is checked on the next run.
                return StepResult::again(20);
            } catch (RequestException $exception) {
                $install->addLog('warning', 'pull_retry', ['path' => $file->path, 'status' => $exception->response->status()]);

                return StepResult::again(10);
            }

            $stat = $files->stat($server, $staged);
            if ($stat === null || $stat['directory'] || $stat['size'] !== $file->size) {
                $this->discard($install, $server, $engine, $staged, $stat !== null && !$stat['directory']);

                throw new StepFailedException('download_incomplete', $file->path);
            }
        } elseif (in_array($file->verify, PlanFile::PANEL_FETCHED, true)) {
            try {
                $contents = PanelDownload::fetch(
                    $file,
                    $engine->workDir($install).'/files/'.bin2hex(random_bytes(8)),
                    $validator,
                    $caps,
                    PanelDownload::timeout($this->elapsedSeconds()) ?? PanelDownload::MIN_FETCH_SECONDS,
                );
            } catch (PackUnavailableException) {
                // The host failed or timed out: a fresh job tries again (attempts are counted above).
                $install->addLog('warning', 'fetch_retry', ['path' => $file->path]);

                return StepResult::again(10);
            }

            $files->write($server, $staged, $contents);
        } else {
            throw new UnsafePlanException('bad_hash', $file->path);
        }

        $this->ensureDirectory($install, $server, $file->directory());
        $files->rename($server, $staged, $file->path);
        $install->addLog('info', 'file_downloaded', ['path' => $file->path, 'verify' => (string) $file->verify]);

        return null;
    }

    /** Removes a rejected staged file; if that fails the install still fails, with the leftover recorded. */
    private function discard(PackInstall $install, Server $server, InstallEngine $engine, string $staged, bool $exists): void
    {
        if (!$exists) {
            return;
        }

        try {
            $this->files()->delete($server, $staged);
        } catch (\Throwable $exception) {
            $leftovers = $install->progressValue('leftovers', []);
            $leftovers[] = $staged;
            $install->setProgress('leftovers', $leftovers);
            $install->addLog('warning', 'leftover', ['path' => $staged]);
            $engine->persist($install);
        }
    }
}
