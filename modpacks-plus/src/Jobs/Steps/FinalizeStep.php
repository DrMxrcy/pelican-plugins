<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\InstallManifest;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use Illuminate\Support\Facades\File;

/**
 * Records the installed-files manifest (plan 36), the pack's Modrinth mods in `mods/.modrinth-metadata.json` for Modrinth Plus
 * (plan 33; best effort, the outcome goes into the summary), removes the (now empty)
 * staging folder, best effort, and marks the install done; the engine writes the summary
 * and removes the panel copy of the archive.
 */
class FinalizeStep extends InstallStep
{
    public const KEY = 'finalize';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        // Plan 40: a version change put no pack on the server, so there is nothing to record.
        if ($install->isVersionChange()) {
            return StepResult::done();
        }

        // Once per install (plan 36): what this install put on the server, for later updates.
        if (!$install->progressValue('manifest_recorded')) {
            $this->recordManifest($install, $engine);
            $install->setProgress('manifest_recorded', true);
            $engine->persist($install);
        }

        // Once per install: a retried finalize keeps the first outcome.
        if ($install->progressValue('modrinth_metadata') === null) {
            $install->setProgress('modrinth_metadata', $this->recordModrinthMetadata($install, $server));
            $engine->persist($install);
        }

        $staging = DownloadStep::stagingRoot($install);

        try {
            if ($this->files()->stat($server, $staging) !== null) {
                $this->files()->delete($server, $staging);
            }
        } catch (\Throwable) {
            $install->addLog('warning', 'leftover', ['path' => $staging]);
        }

        return StepResult::done();
    }

    /**
     * Writes the installed-files manifest. Never fails the install (the pack is in place): when
     * it can't be built the manifest stays empty, which only means this install can't be
     * updated in place later.
     */
    private function recordManifest(PackInstall $install, InstallEngine $engine): void
    {
        try {
            $validator = $engine->validatorFor($install);
            $plan = $validator->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));

            $hashes = [];
            $archive = $plan->overrideArchive();
            if ($plan->overrides !== [] && $archive !== null) {
                // The panel copy the overrides were written from, re-checked against the plan
                // (re-downloaded, allowlisted and hash-checked, if it is gone).
                $path = $engine->workDir($install).'/'.($plan->serverPack !== null ? PackArchive::SERVER_PACK_WORK_FILE : PackArchive::WORK_FILE);
                File::ensureDirectoryExists(dirname($path));
                app(PackProviders::class)->get($install->provider)->fetchArchive($archive, $path, $validator);
                $hashes = InstallManifest::overrideHashes($plan, $path, $validator, Caps::fromConfig());
            }

            $kept = $install->progressValue('kept_modified', []);
            $install->manifest = InstallManifest::build($plan, $validator, $hashes, is_array($kept) ? array_values(array_filter($kept, 'is_string')) : []);
            $install->addLog('info', 'manifest_recorded', ['count' => count($install->manifest)]);
        } catch (\Throwable $exception) {
            report($exception);
            $install->manifest = null;
            $install->addLog('warning', 'manifest_failed', ['detail' => $exception instanceof StepFailedException || $exception instanceof UnsafePlanException || $exception instanceof PackUnavailableException ? $exception->reason : class_basename($exception)]);
        }
    }

    /**
     * Never fails the install: the pack is in place either way.
     *
     * @return array<string, mixed>
     */
    private function recordModrinthMetadata(PackInstall $install, Server $server): array
    {
        try {
            $result = app(ModrinthMetadataWriter::class)->record($server, $this->files(), $install);
        } catch (\Throwable $exception) {
            report($exception);

            $result = ['status' => 'error'];
        }

        // The previous Modrinth Plus list (and its pins) moved aside with mods/: say where.
        $previous = $install->moved_aside_path === null ? null : $install->moved_aside_path.'/'.ModrinthMetadataWriter::PATH;
        try {
            if ($previous !== null && $this->files()->stat($server, $previous) !== null) {
                $result['previous'] = $previous;
            }
        } catch (\Throwable) {
            // Only informational.
        }

        return $result;
    }
}
