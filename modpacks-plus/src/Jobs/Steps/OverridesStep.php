<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;

/**
 * Writes `overrides/` then `server-overrides/` entries (or, for a server pack, the files
 * under its root folder) to the server, one file at a
 * time through the daemon (never a daemon-side unzip). Before writing, the archive's
 * sha512 is checked again (re-downloaded if the panel copy is gone) and every entry
 * name is re-checked; each entry's target is derived again and must equal the plan's.
 * Protected targets are skipped. A pre-existing file the pack did not download is
 * moved aside first.
 */
class OverridesStep extends InstallStep
{
    public const KEY = 'overrides';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $validator = $engine->validatorFor($install);
        $plan = $validator->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));
        $caps = Caps::fromConfig();

        $index = (int) $install->progressValue('override_index', 0);
        if ($index >= count($plan->overrides)) {
            return $this->finished($install, count($plan->overrides));
        }

        $archive = $plan->overrideArchive();
        if ($archive === null) {
            throw new StepFailedException('unexpected', 'no archive');
        }

        // Any provider: the archive is re-checked against the plan's size and hash, re-downloaded
        // (allowlisted, redirect-checked) if the panel copy is gone.
        $path = $engine->workDir($install).'/'.($plan->serverPack !== null ? PackArchive::SERVER_PACK_WORK_FILE : PackArchive::WORK_FILE);
        app(PackProviders::class)->get($install->provider)->fetchArchive($archive, $path, $validator);
        $root = $plan->overrideRoot();

        $reader = PackArchiveReader::open($path, $validator, $caps);
        // Exactly the paths the download step wrote. Case-sensitive on purpose: server
        // filesystems are, so `Mods/x.jar` is a different (pre-existing) file than `mods/x.jar`.
        $downloaded = array_flip(array_map(fn (PlanFile $file) => $file->path, $plan->files));
        $files = $this->files();
        // An update (plan 36) leaves unchanged files and the user's kept changes alone.
        $skip = array_flip(array_filter((array) $install->progressValue('write_skip', []), 'is_string'));

        try {
            while ($index < count($plan->overrides)) {
                if ($this->outOfTime()) {
                    return StepResult::again();
                }

                $entry = $plan->overrides[$index];

                if ($validator->overrideTarget($entry->entry, $root) !== $entry->path) {
                    throw new UnsafePlanException('unsafe_path', $entry->entry);
                }

                if ($validator->isProtected($entry->path)) {
                    $install->addLog('warning', 'override_protected', ['path' => $entry->path]);
                } elseif (!isset($skip[$entry->path])) {
                    $pending = $install->progressValue('pending_override');
                    $stat = $files->stat($server, $entry->path);

                    if ($stat !== null && $stat['directory']) {
                        throw new StepFailedException('path_conflict', $entry->path);
                    }
                    if ($stat !== null && $pending !== $index && !isset($downloaded[$entry->path])) {
                        $this->moveExistingAside($install, $server, $entry->path);
                    }

                    $contents = $reader->read($entry->entry, $caps->maxOverrideBytes);

                    $install->setProgress('pending_override', $index);
                    $engine->persist($install);

                    $directory = dirname($entry->path);
                    $this->ensureDirectory($install, $server, $directory === '.' ? '' : $directory);
                    $files->write($server, $entry->path, $contents);
                }

                $index++;
                $install->setProgress('override_index', $index);
                $install->setProgress('pending_override', null);
                $engine->persist($install);
            }
        } finally {
            $reader->close();
        }

        return $this->finished($install, count($plan->overrides));
    }

    private function finished(PackInstall $install, int $count): StepResult
    {
        $install->addLog('info', 'overrides_applied', ['count' => $count]);

        return $count === 0 ? StepResult::skipped() : StepResult::done();
    }
}
