<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\OverrideArchive;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Writes `overrides/` then `server-overrides/` entries (or, for a server pack, the files
 * under its root folder) to the server. Before writing, the archive's sha512 is checked
 * again (re-downloaded if the panel copy is gone) and every entry name is re-checked; each
 * entry's target is derived again and must equal the plan's. Protected targets are skipped.
 * A pre-existing file the pack did not download is moved aside first.
 *
 * Small sets go one file at a time through the daemon. From `override_archive_min` files on
 * (plan 42) the panel instead builds a zip of exactly those validated entries
 * (OverrideArchive; the pack's own archive never reaches the node), writes it once to
 * `.modpacks-plus/staging/`, has the node unpack it into the server root, removes it and
 * checks every written file's size. That batch runs in phases recorded in progress
 * (`override_batch`): scan (move aside what is in the way), apply (write + unpack per
 * archive; a retry builds and stages a new file, so a re-run after a kill unpacks the same
 * entries again over themselves), cleanup, verify. Each phase resumes where a killed job left it.
 */
class OverridesStep extends InstallStep
{
    public const KEY = 'overrides';

    /** Files from which overrides go as one archive (config `override_archive_min`; 0 = never). */
    public const DEFAULT_ARCHIVE_MIN = 50;

    /** Write + unpack attempts per archive before the install fails. */
    public const MAX_APPLY_ATTEMPTS = 3;

    /** An archive's write + unpack only starts this early in a job, leaving the unpack the rest. */
    public const APPLY_START_SECONDS = 10;

    private const PHASES = ['scan', 'apply', 'cleanup', 'verify'];

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
            $batch = self::batchState($install);
            if ($batch === null && $index === 0 && $install->progressValue('pending_override') === null) {
                [$writes, $protected] = $this->toWrite($plan->overrides, $validator, $root, $skip);
                $minimum = (int) config('modpacks-plus.override_archive_min', self::DEFAULT_ARCHIVE_MIN);
                if ($minimum > 0 && count($writes) >= $minimum) {
                    foreach ($protected as $protectedPath) {
                        $install->addLog('warning', 'override_protected', ['path' => $protectedPath]);
                    }
                    $install->addLog('info', 'overrides_batched', ['count' => count($writes), 'archives' => count(OverrideArchive::chunks($writes))]);
                    $batch = ['phase' => 'scan', 'dir' => 0, 'chunk' => 0, 'attempt' => 0];
                    $install->setProgress('override_batch', $batch);
                    $engine->persist($install);
                }
            }

            if ($batch !== null) {
                [$writes] = $this->toWrite($plan->overrides, $validator, $root, $skip);

                return $this->applyBatch($install, $server, $engine, $files, $reader, $validator, $root, $caps, $writes, $downloaded, $batch, count($plan->overrides));
            }

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

    /**
     * The overrides the step writes (in plan order) and the protected ones it skips. The
     * target of every entry is derived again first, as on the per-file path.
     *
     * @param  array<int, OverrideEntry>  $overrides
     * @param  array<string, int>  $skip
     * @return array{array<int, OverrideEntry>, array<int, string>}
     *
     * @throws UnsafePlanException
     */
    private function toWrite(array $overrides, PlanValidator $validator, ?string $root, array $skip): array
    {
        $writes = [];
        $protected = [];
        foreach ($overrides as $entry) {
            if ($validator->overrideTarget($entry->entry, $root) !== $entry->path) {
                throw new UnsafePlanException('unsafe_path', $entry->entry);
            }

            if ($validator->isProtected($entry->path)) {
                $protected[] = $entry->path;
            } elseif (!isset($skip[$entry->path])) {
                $writes[] = $entry;
            }
        }

        return [$writes, $protected];
    }

    /** @return array{phase: string, dir: int, chunk: int, attempt: int}|null */
    private static function batchState(PackInstall $install): ?array
    {
        $batch = $install->progressValue('override_batch');
        if (!is_array($batch)) {
            return null;
        }

        $phase = $batch['phase'] ?? null;
        if (!in_array($phase, self::PHASES, true)) {
            throw new StepFailedException('unexpected', 'override batch');
        }

        return [
            'phase' => $phase,
            'dir' => max(0, (int) ($batch['dir'] ?? 0)),
            'chunk' => max(0, (int) ($batch['chunk'] ?? 0)),
            'attempt' => max(0, (int) ($batch['attempt'] ?? 0)),
        ];
    }

    /** @param  array{phase: string, dir: int, chunk: int, attempt: int}  $batch */
    private function saveBatch(PackInstall $install, InstallEngine $engine, array $batch): void
    {
        $install->setProgress('override_batch', $batch);
        $engine->persist($install);
    }

    /**
     * The batched path. Every daemon call is bounded: one listing per directory to scan, one
     * write + one unpack per archive, one listing + one delete per staged file, one listing per
     * directory to verify.
     *
     * @param  array<int, OverrideEntry>  $writes
     * @param  array<string, int>  $downloaded
     * @param  array{phase: string, dir: int, chunk: int, attempt: int}  $batch
     *
     * @throws StepFailedException
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function applyBatch(PackInstall $install, Server $server, InstallEngine $engine, ServerFiles $files, PackArchiveReader $reader, PlanValidator $validator, ?string $root, Caps $caps, array $writes, array $downloaded, array $batch, int $count): StepResult
    {
        $directories = self::byDirectory($writes);
        // Whether this job already talked to the node for the batch (then a new archive waits for a fresh job).
        $worked = false;

        try {
            if ($batch['phase'] === 'scan') {
                // Nothing of this batch is on the server yet: anything at a target that the
                // pack did not download is someone else's file and goes aside.
                for (; $batch['dir'] < count($directories); $batch['dir']++) {
                    if ($this->outOfTime()) {
                        $this->saveBatch($install, $engine, $batch);

                        return StepResult::again();
                    }

                    [$directory, $entries] = $directories[$batch['dir']];
                    $worked = true;
                    $listing = $files->list($server, $directory) ?? [];
                    foreach ($entries as $entry) {
                        $stat = $listing[basename($entry->path)] ?? null;
                        if ($stat !== null && $stat['directory']) {
                            throw new StepFailedException('path_conflict', $entry->path);
                        }
                        if ($stat !== null && !isset($downloaded[$entry->path])) {
                            $this->moveExistingAside($install, $server, $entry->path);
                        }
                    }
                    $this->saveBatch($install, $engine, ['phase' => 'scan', 'dir' => $batch['dir'] + 1, 'chunk' => 0, 'attempt' => 0]);
                }

                $batch = ['phase' => 'apply', 'dir' => 0, 'chunk' => 0, 'attempt' => 0];
                $this->saveBatch($install, $engine, $batch);
            }

            if ($batch['phase'] === 'apply') {
                $chunks = OverrideArchive::chunks($writes);
                while ($batch['chunk'] < count($chunks)) {
                    if ($this->outOfTime() || ($worked && $this->elapsedSeconds() > self::APPLY_START_SECONDS)) {
                        return StepResult::again();
                    }
                    if ($batch['attempt'] >= self::MAX_APPLY_ATTEMPTS) {
                        throw new StepFailedException('override_archive_failed', (string) ($batch['chunk'] + 1));
                    }

                    // Counted before anything is sent: a kill mid-way is an attempt too.
                    $batch['attempt']++;
                    $this->saveBatch($install, $engine, $batch);

                    $built = OverrideArchive::build($chunks[$batch['chunk']], $reader, $validator, $root, $caps, $engine->workDir($install).'/overrides-'.$batch['chunk'].'.zip');
                    $staged = OverrideArchive::stagingPath($install->id, $batch['chunk'], $batch['attempt']);

                    try {
                        $this->ensureDirectory($install, $server, OverrideArchive::STAGING_FOLDER);
                        $files->write($server, $staged, $built->contents);
                        $files->decompress($server, $staged, $built->paths);
                    } catch (ConnectionException) {
                        // The node may still be busy; the next attempt stages a fresh file.
                        $install->addLog('warning', 'override_archive_retry', ['archive' => $batch['chunk'] + 1, 'status' => 'timeout']);
                        $engine->persist($install);

                        return StepResult::again(20);
                    } catch (RequestException $exception) {
                        $install->addLog('warning', 'override_archive_retry', ['archive' => $batch['chunk'] + 1, 'status' => $exception->response->status()]);
                        $engine->persist($install);

                        return StepResult::again(10);
                    }

                    $worked = true;
                    $batch = ['phase' => 'apply', 'dir' => 0, 'chunk' => $batch['chunk'] + 1, 'attempt' => 0];
                    $this->saveBatch($install, $engine, $batch);
                }

                $batch = ['phase' => 'cleanup', 'dir' => 0, 'chunk' => 0, 'attempt' => 0];
                $this->saveBatch($install, $engine, $batch);
            }

            if ($batch['phase'] === 'cleanup') {
                // Not best effort: a failed delete retries the job, so no archive outlives the step.
                $this->removeStaged($install, $server, $files, bestEffort: false);
                $batch = ['phase' => 'verify', 'dir' => 0, 'chunk' => 0, 'attempt' => 0];
                $this->saveBatch($install, $engine, $batch);
            }

            // verify: every file the archives held is on the server at its size (an unpack that
            // skipped one, e.g. Wings' file denylist, or died half-way fails here).
            for (; $batch['dir'] < count($directories); $batch['dir']++) {
                if ($this->outOfTime()) {
                    $this->saveBatch($install, $engine, $batch);

                    return StepResult::again();
                }

                [$directory, $entries] = $directories[$batch['dir']];
                $listing = $files->list($server, $directory) ?? [];
                foreach ($entries as $entry) {
                    $stat = $listing[basename($entry->path)] ?? null;
                    if ($stat === null || $stat['directory'] || $stat['size'] !== $entry->size) {
                        throw new StepFailedException('override_incomplete', $entry->path);
                    }
                }
                $this->saveBatch($install, $engine, ['phase' => 'verify', 'dir' => $batch['dir'] + 1, 'chunk' => 0, 'attempt' => 0]);
            }
        } catch (StepFailedException|UnsafePlanException|PackUnavailableException $exception) {
            // The install ends: no staged archive stays behind (best effort, a leftover is logged).
            $this->removeStaged($install, $server, $files, bestEffort: true);

            throw $exception;
        }

        $install->setProgress('override_index', $count);
        $install->setProgress('override_batch', null);
        $engine->persist($install);

        return $this->finished($install, $count);
    }

    /**
     * Entries grouped by parent directory ('' = root), sorted, as a list (a numeric folder
     * name would turn into an int key).
     *
     * @param  array<int, OverrideEntry>  $writes
     * @return array<int, array{string, array<int, OverrideEntry>}>
     */
    private static function byDirectory(array $writes): array
    {
        $groups = [];
        foreach ($writes as $entry) {
            $directory = dirname($entry->path);
            $groups['d:'.($directory === '.' ? '' : $directory)][] = $entry;
        }
        ksort($groups, SORT_STRING);

        $out = [];
        foreach ($groups as $key => $entries) {
            $out[] = [substr((string) $key, 2), $entries];
        }

        return $out;
    }

    /**
     * Deletes every archive staged for this install (found by listing, so archives of attempts
     * a killed job never recorded go too). Best effort: a failure is logged as a leftover
     * instead of thrown.
     */
    private function removeStaged(PackInstall $install, Server $server, ServerFiles $files, bool $bestEffort): void
    {
        try {
            $listing = $files->list($server, OverrideArchive::STAGING_FOLDER) ?? [];
        } catch (\Throwable $exception) {
            if (!$bestEffort) {
                throw $exception;
            }
            $install->addLog('warning', 'leftover', ['path' => OverrideArchive::STAGING_FOLDER.'/'.OverrideArchive::stagingPrefix($install->id).'*']);

            return;
        }

        foreach ($listing as $name => $stat) {
            $path = OverrideArchive::STAGING_FOLDER.'/'.$name;
            if ($stat['directory'] || !str_starts_with((string) $name, OverrideArchive::stagingPrefix($install->id)) || !OverrideArchive::isStagingPath($path)) {
                continue;
            }

            try {
                $files->delete($server, $path);
            } catch (\Throwable $exception) {
                if (!$bestEffort) {
                    throw $exception;
                }
                $install->addLog('warning', 'leftover', ['path' => $path]);
            }
        }
    }

    private function finished(PackInstall $install, int $count): StepResult
    {
        $install->addLog('info', 'overrides_applied', ['count' => $count]);

        return $count === 0 ? StepResult::skipped() : StepResult::done();
    }
}
