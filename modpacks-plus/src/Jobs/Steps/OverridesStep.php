<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
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
use DrMxrcy\ModpacksPlus\Support\JobClock;
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
 * `.modpacks-plus/staging/`, checks its size there, has the node unpack it into the server
 * root, removes it and checks every written file's size. That batch runs in phases recorded
 * in progress (`override_batch`): scan (move aside what is in the way), apply (write + unpack
 * per archive; every try builds and stages a new file, so a re-run after a kill unpacks the
 * same entries again over themselves), cleanup, verify. Each phase resumes where a killed job
 * left it. An archive only starts with MIN_APPLY_SECONDS left in the job; timeouts hand over
 * to a fresh job, only node errors count towards MAX_APPLY_ATTEMPTS. An install that ends
 * mid-batch (cancel, deadline, failure) has its staged archives swept by the engine.
 *
 * Plan 31 fix: a server pack's mod jar over `max_override_bytes` (allowed up to
 * `max_file_bytes`, PlanValidator::overrideCap()) is never read into memory nor zipped: it is
 * extracted to a file on the panel (capped, declared size enforced) and streamed to its target
 * in a write of its own (a chunk of its own in the batch, its size checked on the server),
 * started only with LARGE_WRITE_MIN_SECONDS left; a timeout hands it to a fresh job.
 */
class OverridesStep extends InstallStep
{
    public const KEY = 'overrides';

    /** Files from which overrides go as one archive (config `override_archive_min`; 0 = never). */
    public const DEFAULT_ARCHIVE_MIN = 50;

    /** Node errors (an HTTP error or a staged file of the wrong size) per archive before the install fails. */
    public const MAX_APPLY_ATTEMPTS = 3;

    /**
     * Seconds a job must have left (after JobClock's reserve) to start an archive's write +
     * unpack; with less it hands over to a fresh job without using up an attempt.
     */
    public const MIN_APPLY_SECONDS = 25;

    /**
     * Seconds a job must have left (after JobClock's reserve) to start streaming one large
     * override (plan 31 fix); a fresh job has about 45.
     */
    public const LARGE_WRITE_MIN_SECONDS = 40;

    /** Staged files per archive at most (timeouts don't count as attempts; the step deadline bounds them too). */
    public const MAX_STAGED = 999;

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
                    $install->addLog('info', 'overrides_batched', ['count' => count($writes), 'archives' => count(OverrideArchive::chunks($writes, singleAbove: $caps->maxOverrideBytes))]);
                    $batch = self::state('scan');
                    $install->setProgress('override_batch', $batch);
                    $engine->persist($install);
                }
            }

            if ($batch !== null) {
                [$writes] = $this->toWrite($plan->overrides, $validator, $root, $skip);

                return $this->applyBatch($install, $server, $engine, $files, $reader, $validator, $plan, $caps, $writes, $downloaded, $batch);
            }

            while ($index < count($plan->overrides)) {
                if ($this->outOfTime()) {
                    return StepResult::again();
                }

                $entry = $plan->overrides[$index];

                if ($validator->overrideTarget($entry->entry, $root) !== $entry->path) {
                    throw new UnsafePlanException('unsafe_path', $entry->entry);
                }

                $large = $entry->size > $caps->maxOverrideBytes;

                if ($validator->isProtected($entry->path)) {
                    $install->addLog('warning', 'override_protected', ['path' => $entry->path]);
                } elseif (!isset($skip[$entry->path])) {
                    // A large one starts only with a fresh job's time (nothing moved yet).
                    if ($large && !self::hasLargeWriteTime()) {
                        return StepResult::again();
                    }

                    $pending = $install->progressValue('pending_override');
                    $stat = $files->stat($server, $entry->path);

                    if ($stat !== null && $stat['directory']) {
                        throw new StepFailedException('path_conflict', $entry->path);
                    }
                    if ($stat !== null && $pending !== $index && !isset($downloaded[$entry->path])) {
                        $this->moveExistingAside($install, $server, $entry->path);
                    }

                    $local = null;
                    $contents = '';
                    if ($large) {
                        $local = $this->extractLarge($install, $engine, $reader, $validator, $plan, $entry);
                    } else {
                        $contents = $reader->read($entry->entry, $caps->maxOverrideBytes);
                    }

                    try {
                        $install->setProgress('pending_override', $index);
                        $engine->persist($install);

                        $directory = dirname($entry->path);
                        $this->ensureDirectory($install, $server, $directory === '.' ? '' : $directory);
                        if ($local !== null) {
                            $files->writeFile($server, $entry->path, $local, self::largeWriteTimeout($entry->size));
                        } else {
                            $files->write($server, $entry->path, $contents);
                        }
                    } catch (ConnectionException $exception) {
                        if ($local === null) {
                            throw $exception;
                        }
                        // A slow link: a fresh job writes it again (pending, so it is not moved aside).
                        $install->addLog('warning', 'override_write_retry', ['path' => $entry->path, 'status' => 'timeout']);
                        $engine->persist($install);

                        return StepResult::again(20);
                    } finally {
                        if ($local !== null) {
                            @unlink($local);
                        }
                    }
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

    /** @return array{phase: string, dir: int, chunk: int, attempt: int, failures: int}|null */
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

        return self::state(
            $phase,
            max(0, (int) ($batch['dir'] ?? 0)),
            max(0, (int) ($batch['chunk'] ?? 0)),
            max(0, (int) ($batch['attempt'] ?? 0)),
            max(0, (int) ($batch['failures'] ?? 0)),
        );
    }

    /**
     * `attempt` numbers the staged files of the current archive (each try a new name);
     * `failures` counts the node errors among them.
     *
     * @return array{phase: string, dir: int, chunk: int, attempt: int, failures: int}
     */
    private static function state(string $phase, int $dir = 0, int $chunk = 0, int $attempt = 0, int $failures = 0): array
    {
        return ['phase' => $phase, 'dir' => $dir, 'chunk' => $chunk, 'attempt' => $attempt, 'failures' => $failures];
    }

    /** @param  array{phase: string, dir: int, chunk: int, attempt: int, failures: int}  $batch */
    private function saveBatch(PackInstall $install, InstallEngine $engine, array $batch): void
    {
        $install->setProgress('override_batch', $batch);
        $engine->persist($install);
    }

    /** Whether the job has time for an archive's write and unpack (always outside a job). */
    private static function hasApplyTime(): bool
    {
        $remaining = JobClock::remaining();

        return $remaining === null || $remaining - JobClock::RESERVE_SECONDS >= self::MIN_APPLY_SECONDS;
    }

    /** Whether the job has time to stream one large override (always outside a job). */
    private static function hasLargeWriteTime(): bool
    {
        $remaining = JobClock::remaining();

        return $remaining === null || $remaining - JobClock::RESERVE_SECONDS >= self::LARGE_WRITE_MIN_SECONDS;
    }

    /** Seconds a large override's streamed write may take: 10 s plus 1 s per MiB (JobClock caps it at the job's time left). */
    public static function largeWriteTimeout(int $bytes): int
    {
        return 10 + intdiv(max(0, $bytes), 1024 * 1024);
    }

    /**
     * Extracts a large override (a server pack's mod jar) to a fresh file in the install's work
     * folder, capped by PlanValidator::overrideCap(); the caller removes it.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function extractLarge(PackInstall $install, InstallEngine $engine, PackArchiveReader $reader, PlanValidator $validator, InstallPlan $plan, OverrideEntry $entry): string
    {
        $local = $engine->workDir($install).'/override-'.bin2hex(random_bytes(8)).'.part';
        $reader->extract($entry->entry, $validator->overrideCap($entry->path, $plan), $local);

        return $local;
    }

    /** Seconds the archive write may take: 10 s plus 1 s per MiB, at most 20 (the unpack gets the rest). */
    public static function writeTimeout(int $bytes): int
    {
        return min(20, 10 + intdiv($bytes, 1024 * 1024));
    }

    /**
     * The batched path. Every daemon call is bounded: one listing per directory to scan; per
     * archive one write, one listing (size check), one unpack, one delete; one listing per
     * directory to verify.
     *
     * @param  array<int, OverrideEntry>  $writes
     * @param  array<string, int>  $downloaded
     * @param  array{phase: string, dir: int, chunk: int, attempt: int, failures: int}  $batch
     *
     * @throws StepFailedException
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function applyBatch(PackInstall $install, Server $server, InstallEngine $engine, ServerFiles $files, PackArchiveReader $reader, PlanValidator $validator, InstallPlan $plan, Caps $caps, array $writes, array $downloaded, array $batch): StepResult
    {
        $root = $plan->overrideRoot();
        $count = count($plan->overrides);
        $directories = self::byDirectory($writes);

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
                    $this->saveBatch($install, $engine, self::state('scan', $batch['dir'] + 1));
                }

                $batch = self::state('apply');
                $this->saveBatch($install, $engine, $batch);
            }

            if ($batch['phase'] === 'apply') {
                $chunks = OverrideArchive::chunks($writes, singleAbove: $caps->maxOverrideBytes);
                while ($batch['chunk'] < count($chunks)) {
                    $chunk = $chunks[$batch['chunk']];
                    // Plan 31 fix: a large server-pack jar, alone in its chunk, is streamed to its target.
                    $direct = count($chunk) === 1 && $chunk[0]->size > $caps->maxOverrideBytes;

                    // Too little time left: a fresh job starts it, no attempt used.
                    if ($this->outOfTime() || !($direct ? self::hasLargeWriteTime() : self::hasApplyTime())) {
                        return StepResult::again();
                    }
                    if ($batch['failures'] >= self::MAX_APPLY_ATTEMPTS || $batch['attempt'] >= self::MAX_STAGED) {
                        throw $direct
                            ? new StepFailedException('override_write_failed', $chunk[0]->path)
                            : new StepFailedException('override_archive_failed', (string) ($batch['chunk'] + 1));
                    }

                    // Recorded before anything is sent: every try stages a file of its own name.
                    $batch['attempt']++;
                    $this->saveBatch($install, $engine, $batch);

                    if ($direct) {
                        $entry = $chunk[0];
                        $local = $this->extractLarge($install, $engine, $reader, $validator, $plan, $entry);

                        try {
                            $directory = dirname($entry->path);
                            $this->ensureDirectory($install, $server, $directory === '.' ? '' : $directory);
                            $files->writeFile($server, $entry->path, $local, self::largeWriteTimeout($entry->size));

                            $stat = $files->stat($server, $entry->path);
                            if ($stat === null || $stat['directory'] || $stat['size'] !== $entry->size) {
                                return $this->applyFailed($install, $engine, $batch, 'size', $entry->path);
                            }
                        } catch (ConnectionException) {
                            // A slow link or a busy node: a fresh job writes it again, no attempt used.
                            $install->addLog('warning', 'override_write_retry', ['path' => $entry->path, 'status' => 'timeout']);
                            $engine->persist($install);

                            return StepResult::again(20);
                        } catch (RequestException $exception) {
                            return $this->applyFailed($install, $engine, $batch, (string) $exception->response->status(), $entry->path);
                        } finally {
                            @unlink($local);
                        }

                        $batch = self::state('apply', 0, $batch['chunk'] + 1);
                        $this->saveBatch($install, $engine, $batch);

                        continue;
                    }

                    $built = OverrideArchive::build($chunk, $reader, $validator, $root, $caps, $engine->workDir($install).'/overrides-'.$batch['chunk'].'.zip');
                    $staged = OverrideArchive::stagingPath($install->id, $batch['chunk'], $batch['attempt']);

                    try {
                        $this->ensureDirectory($install, $server, OverrideArchive::STAGING_FOLDER);
                        $files->write($server, $staged, $built->contents, self::writeTimeout(strlen($built->contents)));

                        // Defence in depth: only a staged file of exactly the built size is unpacked.
                        $stat = $files->stat($server, $staged);
                        if ($stat === null || $stat['directory'] || $stat['size'] !== strlen($built->contents)) {
                            return $this->applyFailed($install, $engine, $batch, 'size');
                        }

                        $files->decompress($server, $staged, $built->paths);
                    } catch (ConnectionException) {
                        // A slow link or a busy node, not a node error: a fresh job tries again.
                        $install->addLog('warning', 'override_archive_retry', ['archive' => $batch['chunk'] + 1, 'status' => 'timeout']);
                        $engine->persist($install);

                        return StepResult::again(20);
                    } catch (RequestException $exception) {
                        return $this->applyFailed($install, $engine, $batch, (string) $exception->response->status());
                    }

                    $batch = self::state('apply', 0, $batch['chunk'] + 1);
                    $this->saveBatch($install, $engine, $batch);

                    // Recorded first: a failed delete retries the job, the cleanup phase removes the rest.
                    $files->delete($server, $staged);
                }

                $batch = self::state('cleanup');
                $this->saveBatch($install, $engine, $batch);
            }

            if ($batch['phase'] === 'cleanup') {
                // Not best effort: a failed delete retries the job, so no archive outlives the step.
                self::removeStaged($install, $server, $files, bestEffort: false);
                $batch = self::state('verify');
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
                $this->saveBatch($install, $engine, self::state('verify', $batch['dir'] + 1));
            }
        } catch (StepFailedException|UnsafePlanException|PackUnavailableException $exception) {
            // The install ends: no staged archive stays behind (best effort, a leftover is logged).
            self::removeStaged($install, $server, $files, bestEffort: true);

            throw $exception;
        }

        $install->setProgress('override_index', $count);
        $install->setProgress('override_batch', null);
        $engine->persist($install);

        return $this->finished($install, $count);
    }

    /**
     * A node error for the current archive (an HTTP error, or a staged file that is not what
     * was built) or for a large override written directly ($path): counted, and a fresh job
     * tries again.
     *
     * @param  array{phase: string, dir: int, chunk: int, attempt: int, failures: int}  $batch
     */
    private function applyFailed(PackInstall $install, InstallEngine $engine, array $batch, string $status, ?string $path = null): StepResult
    {
        $batch['failures']++;
        if ($path !== null) {
            $install->addLog('warning', 'override_write_retry', ['path' => $path, 'status' => $status]);
        } else {
            $install->addLog('warning', 'override_archive_retry', ['archive' => $batch['chunk'] + 1, 'status' => $status]);
        }
        $this->saveBatch($install, $engine, $batch);

        return StepResult::again(10);
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
     * instead of thrown. Also used by the engine when an install ends mid-batch.
     */
    public static function removeStaged(PackInstall $install, Server $server, ServerFiles $files, bool $bestEffort): void
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
