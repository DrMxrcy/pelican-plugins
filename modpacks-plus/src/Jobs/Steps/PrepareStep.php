<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\NotEnoughDiskException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Services\PackSizes;
use DrMxrcy\ModpacksPlus\Services\ServerDiskUsage;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\DiskBudget;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Support\ServerProperties;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

/**
 * Reads and validates the pack before anything on the server changes: finds the
 * worlds to protect, resolves the plan (archive download, sha512, zip-slip, index,
 * caps, hosts, hashes) and checks the pack's Minecraft version and loader against
 * the server (or, for a loader switch, against the confirmed switch target). Only reads
 * from the server.
 */
class PrepareStep extends InstallStep
{
    public const KEY = 'prepare';

    /** Top-level folders checked for level.dat; more than this and the install stops (fail closed). */
    public const MAX_ROOT_FOLDERS = 200;

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->plan !== null) {
            return StepResult::done();
        }

        if ($install->isLoaderSwitch()) {
            // The pack must be exactly what the switch was confirmed for.
            $minecraft = (string) ($install->loader_switch['minecraft'] ?? '');
            $loader = (string) ($install->loader_switch['loader'] ?? '');
            if (!ServerTarget::isMinecraft($server) || $minecraft === '' || $loader === '') {
                throw new StepFailedException('server_not_supported');
            }
        } else {
            $minecraft = ServerTarget::minecraftVersion($server);
            $loader = ServerTarget::loader($server);
            if ($minecraft === null || $loader === null) {
                throw new StepFailedException('server_not_supported');
            }
        }

        $worlds = $this->worlds($server);
        $caps = Caps::fromConfig();
        $validator = new PlanValidator($caps, $worlds, PlanValidator::hostsFor($install->provider), $this->diskBudget($install, $server, $caps));

        try {
            $plan = app(PackProviders::class)
                ->get($install->provider)
                ->resolvePlan($install->pack_id, $install->version_id, $validator, $engine->workDir($install));
        } catch (NotEnoughDiskException $exception) {
            // Lets the Modpacks page explain this version won't fit before anyone tries again.
            PackSizes::remember($install->provider, $install->pack_id, $install->version_id, $exception->packBytes);

            throw $exception;
        }
        PackSizes::remember($install->provider, $install->pack_id, $install->version_id, $plan->totalBytes());

        if ($plan->minecraftVersion !== $minecraft || $plan->loader !== $loader) {
            throw new StepFailedException('target_mismatch', trim($plan->loader.' '.$plan->minecraftVersion));
        }

        $install->plan = $plan->toArray();
        $install->version_number ??= $plan->versionNumber;
        $install->setProgress('worlds', $worlds);
        $install->addLog('info', 'prepared', [
            'files' => count($plan->files),
            'overrides' => count($plan->overrides),
            'skipped' => count($plan->skippedClientFiles),
            'panel' => count(array_filter($plan->files, fn ($file) => $file->verify === PlanFile::VERIFY_PANEL)),
        ]);
        foreach ($plan->skippedOverrides as $path) {
            $install->addLog('warning', 'override_protected', ['path' => $path]);
        }
        if ($plan->serverPack !== null) {
            $install->addLog('info', 'server_pack', ['name' => $plan->serverPack->filename]);
        } elseif ($plan->serverPackFallback !== null) {
            $install->addLog('warning', 'server_pack_fallback', ['reason' => $plan->serverPackFallback['reason']]);
        }
        foreach ($plan->manualFiles as $file) {
            $install->addLog('warning', 'manual_download', ['name' => $file['name']]);
        }

        return StepResult::done();
    }

    /**
     * Plan 38: the whole-pack limit for this server, checked by the validator before the
     * backup and before any file changes. The node is asked for usage only when the server
     * has a disk limit; unknown usage falls back to the configured cap (logged), never to
     * unlimited and never to a refusal.
     */
    private function diskBudget(PackInstall $install, Server $server, Caps $caps): DiskBudget
    {
        $diskMb = max(0, (int) $server->disk);
        $used = $diskMb > 0 ? app(ServerDiskUsage::class)->usedBytes($server) : null;
        $budget = DiskBudget::forServer($diskMb, $used, $caps);

        if (!$budget->limited()) {
            $install->addLog('info', 'disk_unlimited', ['cap' => DiskBudget::human($budget->capBytes)]);
        } elseif (!$budget->usageKnown()) {
            $install->addLog('warning', 'disk_usage_unknown', ['cap' => DiskBudget::human($budget->capBytes)]);
        } else {
            $install->addLog('info', 'disk_budget', [
                'free' => DiskBudget::human($budget->freeBytes() ?? 0),
                'limit' => DiskBudget::human($budget->limitBytes),
                'headroom' => DiskBudget::human($budget->headroomBytes),
                'allowed' => DiskBudget::human($budget->allowedBytes()),
            ]);
        }

        return $budget;
    }

    /**
     * World folders: `level-name` (default `world`) plus every top-level folder holding
     * level.dat. An unreadable server.properties stops the install (never guessed).
     *
     * @return array<int, string>
     */
    private function worlds(Server $server): array
    {
        return self::findWorlds($this->files(), $server);
    }

    /**
     * worlds() for another step (plan 40, a version change has no pack to check).
     *
     * @return array<int, string>
     *
     * @throws StepFailedException
     */
    public static function findWorlds(ServerFiles $files, Server $server): array
    {
        try {
            $properties = $files->read($server, 'server.properties', 1024 * 1024);
        } catch (FileNotFoundException) {
            $properties = '';
        } catch (\Throwable $exception) {
            throw new StepFailedException('server_properties_unreadable', class_basename($exception), $exception);
        }

        // Escaped keys or continuations fail closed (unsafe_level_name).
        $levelName = ServerProperties::levelName($properties) ?? 'world';

        $worlds = [$levelName];

        $root = $files->list($server, '') ?? [];
        $folders = array_keys(array_filter($root, fn (array $entry) => $entry['directory']));
        if (count($folders) > self::MAX_ROOT_FOLDERS) {
            throw new StepFailedException('too_many_folders', (string) count($folders));
        }

        foreach ($folders as $folder) {
            $folder = (string) $folder;
            // A name no pack path can ever match can't be touched either; skip it.
            if (!PlanValidator::isSafeRelativePath($folder) || in_array($folder, $worlds, true)) {
                continue;
            }

            if (isset(($files->list($server, $folder) ?? [])['level.dat'])) {
                $worlds[] = $folder;
            }
        }

        return $worlds;
    }
}
