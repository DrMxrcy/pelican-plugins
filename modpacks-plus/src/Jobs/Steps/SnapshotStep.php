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
use DrMxrcy\ModpacksPlus\Support\ServerTarget;

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

        $plan = $install->installPlan();
        if ($plan === null) {
            throw new StepFailedException('unexpected', 'no plan');
        }

        $loader = (string) ($switch['loader'] ?? '');
        $egg = Egg::query()->find((int) ($switch['egg_id'] ?? 0));
        if ($egg === null) {
            throw new StepFailedException('egg_missing', LoaderEggResolver::loaderName($loader));
        }
        if (ServerTarget::eggLoader($egg) !== $loader || EggVariableMap::loaderFor($egg->uuid, $egg->update_url) !== $loader) {
            throw new StepFailedException('egg_unknown', $egg->name);
        }

        $variables = EggVariableMap::variables($loader, $plan->minecraftVersion, $plan->loaderVersion);
        ChangeEggStep::checkVariables($egg, $variables);
        $image = (new JavaImagePicker())->pick($egg->docker_images ?? [], $plan->minecraftVersion);

        $server->refresh();
        $server->loadMissing(['egg', 'mounts']);

        // The values the server runs with: its own, else the egg's default.
        $previous = [];
        foreach ($server->variables()->get() as $variable) {
            $previous[$variable->env_variable] = (string) ($variable->server_value ?? $variable->default_value);
        }

        $install->snapshot = [
            'egg_id' => $server->egg_id,
            'egg_uuid' => $server->egg->uuid,
            'egg_name' => $server->egg->name,
            'startup' => $server->startup,
            'image' => $server->image,
            'variables' => $previous,
            'mounts' => $server->mounts->map(fn ($mount) => ['id' => $mount->id, 'name' => $mount->name])->values()->all(),
        ];

        $install->loader_switch = array_merge($switch, [
            'egg_uuid' => $egg->uuid,
            'loader_version' => $plan->loaderVersion,
            'variables' => $variables,
            'image' => $image['image'],
            'image_label' => $image['label'],
            'java' => $image['java'],
        ]);

        $install->addLog('info', 'snapshot_taken', ['egg' => $server->egg->name, 'image' => (string) $server->image]);
        if (!EggVariableMap::pinsLoaderVersion($loader)) {
            $install->addLog('warning', 'loader_version_unpinned', ['loader' => LoaderEggResolver::loaderName($loader), 'version' => (string) $plan->loaderVersion]);
        }

        return StepResult::done();
    }
}
