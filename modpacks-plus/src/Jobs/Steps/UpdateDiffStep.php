<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\InstallManifest;
use DrMxrcy\ModpacksPlus\Services\PackDiff;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;

/**
 * Update (plan 36): decides what happens to each file. Stops the server, then compares the
 * installed version's manifest with the new version's (overrides hashed from the new archive)
 * and with the server's files: sizes from directory listings, and a hash for every file about
 * to move at its old size (read through the daemon, up to max_panel_fetch_bytes; a larger
 * file is judged by size). Only reads. The decisions go into progress for UpdateApplyStep and
 * the download and overrides steps; a retried run reuses the hashes it already read.
 */
class UpdateDiffStep extends InstallStep
{
    public const KEY = 'update_diff';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->progressValue('update_decided')) {
            return StepResult::done();
        }

        // Nothing moves while the server runs (it rewrites configs and holds the mods open).
        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        $previous = PackInstall::query()->whereKey((int) $install->previous_install_id)->where('server_id', $server->id)->first();
        $old = $previous?->manifestEntries();
        if ($previous === null || $old === null || $previous->provider !== $install->provider || $previous->pack_id !== $install->pack_id) {
            throw new StepFailedException('update_no_manifest', (string) $previous?->name);
        }

        $validator = $engine->validatorFor($install);
        $plan = $validator->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));
        $caps = Caps::fromConfig();

        $hashes = [];
        $archive = $plan->overrideArchive();
        if ($plan->overrides !== [] && $archive !== null) {
            $path = $engine->workDir($install).'/'.($plan->serverPack !== null ? PackArchive::SERVER_PACK_WORK_FILE : PackArchive::WORK_FILE);
            File::ensureDirectoryExists(dirname($path));
            app(PackProviders::class)->get($install->provider)->fetchArchive($archive, $path, $validator);
            $hashes = InstallManifest::overrideHashes($plan, $path, $validator, $caps);
        }
        $new = InstallManifest::entries(InstallManifest::build($plan, $validator, $hashes)) ?? [];

        // A path the old manifest has that is protected now (a world was renamed onto it, or the
        // record was tampered with) is never looked at or moved.
        foreach (array_keys($old) as $path) {
            if ($validator->isProtected($path)) {
                unset($old[$path]);
                $install->addLog('warning', 'kept_protected', ['path' => $path]);
            }
        }

        $disk = $this->sizes($server, array_values(array_unique(array_merge(array_keys($old), array_keys($new)))));

        $read = $install->progressValue('diff_hashes', []);
        $read = is_array($read) ? $read : [];
        foreach (PackDiff::needsHash($old, $new, $disk) as $path) {
            if (!array_key_exists($path, $read)) {
                if ($this->outOfTime()) {
                    $install->setProgress('diff_hashes', $read);

                    return StepResult::again();
                }
                $read[$path] = $this->hashOf($server, $path, $old[$path]['hash'], $disk[$path]['size'] ?? 0, $caps);
            }
            if ($disk[$path] !== null && is_string($read[$path])) {
                $disk[$path]['hash'] = $read[$path];
            }
        }

        $decisions = PackDiff::plan($old, $new, $disk, $install->option('overwrite_changes'));
        $moves = [];
        $skip = [];
        $kept = [];
        foreach ($decisions as $path => $decision) {
            match ($decision['action']) {
                PackDiff::ASIDE, PackDiff::ASIDE_WRITE => $moves[] = $path,
                default => null,
            };
            if (in_array($decision['action'], [PackDiff::KEEP, PackDiff::KEEP_MODIFIED], true) && isset($new[$path])) {
                $skip[] = $path;
            }
            if ($decision['action'] === PackDiff::KEEP_MODIFIED) {
                $kept[] = $path;
                $install->addLog('warning', 'kept_modified', ['path' => $path]);
            }
        }

        $summary = PackDiff::summary($decisions);
        $install->setProgress('update_moves', $moves);
        $install->setProgress('write_skip', $skip);
        $install->setProgress('kept_modified', $kept);
        $install->setProgress('update_summary', $summary);
        $install->setProgress('diff_hashes', null);
        $install->setProgress('update_decided', true);
        $install->addLog('info', 'update_diffed', [
            'added' => $summary['added'],
            'changed' => $summary['changed'],
            'removed' => $summary['removed'],
            'unchanged' => $summary['unchanged'],
            'kept' => count($kept),
        ]);

        return StepResult::done();
    }

    /**
     * The server's state of each path: null when absent, else its size (-1 for a directory,
     * which never matches a file). Each parent directory is listed once.
     *
     * @param  array<int, string>  $paths
     * @return array<string, array{size: int, hash: ?string}|null>
     */
    private function sizes(Server $server, array $paths): array
    {
        $listings = [];
        $states = [];
        foreach ($paths as $path) {
            $directory = dirname($path);
            $directory = $directory === '.' ? '' : $directory;
            if (!array_key_exists($directory, $listings)) {
                $listings[$directory] = $this->files()->list($server, $directory);
            }

            $entry = ($listings[$directory] ?? [])[basename($path)] ?? null;
            $states[$path] = $entry === null ? null : ['size' => $entry['directory'] ? -1 : $entry['size'], 'hash' => null];
        }

        return $states;
    }

    /** The file's hash in the manifest's algorithm, or null when it is too large to read (judged by size). */
    private function hashOf(Server $server, string $path, string $manifestHash, int $size, Caps $caps): ?string
    {
        $split = InstallManifest::splitHash($manifestHash);
        if ($split === null || $size > $caps->maxPanelFetchBytes) {
            return null;
        }

        try {
            $contents = $this->files()->read($server, $path, $caps->maxPanelFetchBytes);
        } catch (FileNotFoundException) {
            return null;
        }

        return $split[0].':'.hash($split[0], $contents);
    }
}
