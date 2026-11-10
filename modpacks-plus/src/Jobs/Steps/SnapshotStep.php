<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Egg;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\JavaImagePicker;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;

/**
 * Loader switch, before anything changes: records the server's egg, startup, image,
 * variables and mounts on the install (so the previous setup can be read back; nothing
 * is restored onto the new egg automatically), and works out the target: the egg is
 * re-checked, the version variables come from the pack and the egg table, the image
 * from JavaImagePicker, and every value is validated against the egg's own rules.
 * Only reads.
 */
class SnapshotStep extends InstallStep
{
    public const KEY = 'snapshot';

    protected const TOUCHES_FILES = false;

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $switch = $install->loader_switch ?? [];
        if ($install->snapshot !== null && isset($switch['image'])) {
            return StepResult::done();
        }

        // The versions come from the pack, or (plan 40, a version change) from the confirmed choice.
        if ($install->isVersionChange()) {
            $minecraft = (string) ($switch['minecraft'] ?? '');
            $loaderVersion = is_string($switch['loader_version'] ?? null) ? $switch['loader_version'] : null;
        } else {
            $plan = $install->installPlan();
            if ($plan === null) {
                throw new StepFailedException('unexpected', 'no plan');
            }
            $minecraft = $plan->minecraftVersion;
            $loaderVersion = $plan->loaderVersion;
        }

        $loader = (string) ($switch['loader'] ?? '');
        $egg = Egg::query()->find((int) ($switch['egg_id'] ?? 0));
        if ($egg === null) {
            throw new StepFailedException('egg_missing', LoaderEggResolver::loaderName($loader));
        }
        $variables = EggVariableMap::variables($loader, $minecraft, $loaderVersion);
        $image = (new JavaImagePicker())->pick($egg->docker_images ?? [], $minecraft);

        $server->refresh();
        $server->loadMissing(['egg', 'mounts']);

        // Refused here, before the backup or any file change, if the switch can't be done cleanly.
        ChangeEggStep::checkTarget($server, $egg, $loader, $image['image'], $variables);

        // The values the server runs with: its own, else the egg's default. Variables users
        // can't see (passwords, keys) are recorded by name only: the record is shown to
        // everyone who can open the page.
        $previous = [];
        $hidden = [];
        foreach ($server->variables()->get() as $variable) {
            if ($variable->user_viewable) {
                $previous[$variable->env_variable] = (string) ($variable->server_value ?? $variable->default_value);
            } else {
                $hidden[] = $variable->env_variable;
            }
        }

        $install->snapshot = [
            'egg_id' => $server->egg_id,
            'egg_uuid' => $server->egg->uuid,
            'egg_name' => $server->egg->name,
            'startup' => $server->startup,
            'image' => $server->image,
            'variables' => $previous,
            'hidden_variables' => $hidden,
            'mounts' => $server->mounts->map(fn ($mount) => ['id' => $mount->id, 'name' => $mount->name])->values()->all(),
        ];

        $install->loader_switch = array_merge($switch, [
            'egg_uuid' => $egg->uuid,
            'loader_version' => $loaderVersion,
            'variables' => $variables,
            'image' => $image['image'],
            'image_label' => $image['label'],
            'java' => $image['java'],
        ]);

        $install->addLog('info', 'snapshot_taken', ['egg' => $server->egg->name]);
        if (!EggVariableMap::pinsLoaderVersion($loader)) {
            $install->addLog('warning', 'loader_version_unpinned', ['loader' => LoaderEggResolver::loaderName($loader), 'version' => (string) $loaderVersion]);
        }

        return StepResult::done();
    }
}
