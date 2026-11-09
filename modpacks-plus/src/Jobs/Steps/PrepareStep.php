<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackProviders;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use DrMxrcy\ModpacksPlus\Support\ServerProperties;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

/**
 * Reads and validates the pack before anything on the server changes: finds the
 * worlds to protect, resolves the plan (archive download, sha512, zip-slip, index,
 * caps, hosts, hashes) and checks the pack's Minecraft version and loader against
 * the server. Only reads from the server.
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

        $minecraft = ServerTarget::minecraftVersion($server);
        $loader = ServerTarget::loader($server);
        if ($minecraft === null || $loader === null) {
            throw new StepFailedException('server_not_supported');
        }

        $worlds = $this->worlds($server);
        $validator = new PlanValidator(Caps::fromConfig(), $worlds);

        $plan = app(PackProviders::class)
            ->get($install->provider)
            ->resolvePlan($install->pack_id, $install->version_id, $validator, $engine->workDir($install));

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

        return StepResult::done();
    }

    /**
     * World folders: `level-name` (default `world`) plus every top-level folder holding
     * level.dat. An unreadable server.properties stops the install (never guessed).
     *
     * @return array<int, string>
     */
    private function worlds(Server $server): array
    {
        $files = $this->files();

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
