<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;

/**
 * Records the pack's Modrinth mods in `mods/.modrinth-metadata.json` for Modrinth Plus
 * (plan 33; best effort, the outcome goes into the summary), removes the (now empty)
 * staging folder, best effort, and marks the install done; the engine writes the summary
 * and removes the panel copy of the archive.
 */
class FinalizeStep extends InstallStep
{
    public const KEY = 'finalize';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
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
