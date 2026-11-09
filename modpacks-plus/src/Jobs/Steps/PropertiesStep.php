<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Services\PropertiesPreserver;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;

/**
 * A pack that ships `server.properties` (plan 36): its file is merged into the server's by
 * PropertiesPreserver (the server's comments, order and unknown keys kept, the preserved keys
 * and level-name kept, ports from the allocation), never written over it. Packs without one
 * skip this step.
 *
 * server.properties is protected, so the daemon adapter refuses to write it. The merge goes in
 * the one way a protected file may move, ServerFiles::movePreserved(), never replacing anything:
 * the merged file is written to `<previous>/merged/server.properties`, the server's own is moved
 * to `<previous>/server.properties` (recorded first in progress.moved, so "Restore previous
 * files" puts it back), then the merged one is moved into place. Each run decides from what is
 * on disk, so a retry continues; a file a server start created meanwhile is kept in
 * `.modpacks-plus/replaced-<date>-<id>/`. Not cancellable while the swap is under way.
 * A file that can't be merged (escaped keys, continuations, too large, an unsafe level-name on
 * the server) is left untouched.
 */
class PropertiesStep extends InstallStep
{
    public const KEY = 'properties';

    public const FILE = 'server.properties';

    public static function cancellableAt(PackInstall $install): bool
    {
        return !$install->progressValue('properties_swapping');
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $state = $install->progressValue('properties');
        if (in_array($state, ['merged', 'unchanged', 'unmergeable', 'kept'], true)) {
            return StepResult::done();
        }

        $validator = $engine->validatorFor($install);
        $plan = $validator->validate($install->installPlan() ?? throw new StepFailedException('unexpected', 'no plan'));
        $entry = self::packEntry($plan);
        if ($entry === null) {
            return StepResult::skipped();
        }

        if (!$this->ensureStopped($install, $server, $engine)) {
            return StepResult::again(self::STOP_POLL_SECONDS);
        }

        if ($install->moved_aside_path === null) {
            $install->moved_aside_path = PlanValidator::OWN_FOLDER.'/previous-'.now()->format('Ymd-His').'-'.$install->id;
            $engine->persist($install);
        }
        $aside = $install->moved_aside_path;
        $staged = "$aside/merged/".self::FILE;
        $old = "$aside/".self::FILE;
        $files = $this->files();

        if (!$install->progressValue('properties_swapping')) {
            try {
                $current = $this->current($server);
                $report = PropertiesPreserver::mergeReport($current ?? '', $this->packFile($install, $engine, $plan, $entry, $validator), PropertiesPreserver::keys(config('modpacks-plus.preserved_properties')), self::port($server));
                $merged = $report['contents'];
            } catch (UnsafePlanException $exception) {
                // Fail closed for the merge: nothing is written, the server's file stays as it is
                // (the pack's files are already in place, so the install itself carries on).
                if (!in_array($exception->reason, ['properties_unmergeable', 'unsafe_level_name'], true)) {
                    throw $exception;
                }
                $install->setProgress('properties', 'unmergeable');
                $install->addLog('warning', 'properties_unmergeable', ['detail' => $exception->reason.': '.$exception->detail]);

                return StepResult::done();
            }

            // The pack's keys a pack may not set (names only), for the summary.
            $install->setProgress('properties_dropped', $report['dropped']);

            if ($current !== null && $merged === $current) {
                $install->setProgress('properties', 'unchanged');
                $install->addLog('info', 'properties_unchanged');

                return StepResult::done();
            }

            // Our own file: a retried run simply writes it again.
            $files->createDirectory($server, dirname($staged));
            $files->write($server, $staged, $merged);
            $install->setProgress('properties_swapping', true);
            $engine->persist($install);
        }

        $atRoot = $files->stat($server, self::FILE) !== null;
        $oldAside = $files->stat($server, $old) !== null;

        if ($files->stat($server, $staged) === null) {
            if (!$atRoot && $oldAside) {
                // The merged file is gone: put the server's own back rather than leave none.
                $files->movePreserved($server, $old, self::FILE);
                $install->addLog('warning', 'properties_kept', ['path' => $old]);
                $install->setProgress('properties', 'kept');
            } else {
                $install->setProgress('properties', 'merged');
            }

            return $this->finish($install);
        }

        if ($atRoot && !$oldAside) {
            $moved = $install->movedFolders();
            if (!in_array(self::FILE, $moved, true)) {
                $moved[] = self::FILE;
                $install->setProgress('moved', $moved);
                $engine->persist($install);
            }
            $files->movePreserved($server, self::FILE, $old);
        } elseif ($atRoot) {
            // Created after the server's own was set aside (a server start): kept, not replaced.
            $replaced = PlanValidator::OWN_FOLDER.'/replaced-'.now()->format('Ymd-His').'-'.$install->id.'/'.self::FILE;
            $files->createDirectory($server, dirname($replaced));
            $files->movePreserved($server, self::FILE, $replaced);
            $install->addLog('warning', 'restored_replaced', ['path' => self::FILE, 'to' => $replaced]);
        }

        $files->movePreserved($server, $staged, self::FILE);
        $install->setProgress('properties', 'merged');
        $install->addLog('info', 'properties_merged', ['path' => $oldAside || $atRoot ? $old : '']);

        return $this->finish($install);
    }

    private function finish(PackInstall $install): StepResult
    {
        $install->setProgress('properties_swapping', null);

        return StepResult::done();
    }

    /**
     * The archive entry holding the pack's server.properties, or null: `server-overrides/` wins
     * over `overrides/`; a server pack's sits under its root. Only when the plan set it aside as
     * a protected override (PlanValidator), so it is exactly the file the plan names.
     */
    public static function packEntry(InstallPlan $plan): ?string
    {
        if (!in_array(self::FILE, $plan->skippedOverrides, true)) {
            return null;
        }

        $root = $plan->overrideRoot();

        return $root === null ? 'overrides/'.self::FILE : $root.self::FILE;
    }

    /** The pack's file from its archive on the panel (re-fetched and hash-checked if gone). */
    private function packFile(PackInstall $install, InstallEngine $engine, InstallPlan $plan, string $entry, PlanValidator $validator): string
    {
        $archive = $plan->overrideArchive() ?? throw new StepFailedException('unexpected', 'no archive');
        $path = $engine->workDir($install).'/'.($plan->serverPack !== null ? PackArchive::SERVER_PACK_WORK_FILE : PackArchive::WORK_FILE);
        File::ensureDirectoryExists(dirname($path));
        app(PackProviders::class)->get($install->provider)->fetchArchive($archive, $path, $validator);

        $reader = PackArchiveReader::open($path, $validator, Caps::fromConfig());
        try {
            if ($plan->overrideRoot() === null && $reader->has('server-overrides/'.self::FILE)) {
                $entry = 'server-overrides/'.self::FILE;
            }
            if (!$reader->has($entry) || $validator->overrideTarget($entry, $plan->overrideRoot()) !== self::FILE) {
                throw new UnsafePlanException('properties_unmergeable', $entry);
            }
            if (($reader->size($entry) ?? 0) > PropertiesPreserver::MAX_BYTES) {
                throw new UnsafePlanException('properties_unmergeable', 'too large');
            }

            return $reader->read($entry, PropertiesPreserver::MAX_BYTES);
        } finally {
            $reader->close();
        }
    }

    /**
     * The server's server.properties, or null when it has none.
     *
     * @throws UnsafePlanException `properties_unmergeable` when it is too large to merge
     */
    private function current(Server $server): ?string
    {
        $stat = $this->files()->stat($server, self::FILE);
        if ($stat === null) {
            return null;
        }
        if ($stat['directory'] || $stat['size'] > PropertiesPreserver::MAX_BYTES) {
            throw new UnsafePlanException('properties_unmergeable', 'server.properties is too large or not a file');
        }

        try {
            return $this->files()->read($server, self::FILE, PropertiesPreserver::MAX_BYTES);
        } catch (FileNotFoundException) {
            return null;
        } catch (\Throwable $exception) {
            throw new StepFailedException('server_properties_unreadable', class_basename($exception), $exception);
        }
    }

    private static function port(Server $server): ?int
    {
        $port = $server->allocation?->port;

        return is_numeric($port) ? (int) $port : null;
    }
}
