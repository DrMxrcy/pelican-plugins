<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Eggs\EggChangerService;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Loader switch: moves this server (only) to the loader egg with the panel's
 * EggChangerService (new startup and default image, variables kept by name, mounts the
 * egg doesn't allow detached), then sets the version variables and the picked Java
 * image. One transaction under a row lock; the egg itself is never written. Idempotent:
 * a server already on the target egg just gets the same values again.
 */
class ChangeEggStep extends InstallStep
{
    public const KEY = 'change_egg';

    public static function cancellableAt(PackInstall $install): bool
    {
        return false;
    }

    /**
     * Every variable value the server will have on $egg after the change, the way
     * EggChangerService fills them (values kept by env name, else the egg default; nothing
     * kept when the egg stays the same), with the version variables on top.
     *
     * @param  array<string, string>  $versions
     * @return array<string, string>
     */
    public static function targetValues(Server $server, Egg $egg, array $versions): array
    {
        $current = [];
        foreach (ServerVariable::query()->where('server_id', $server->id)->with('variable')->get() as $serverVariable) {
            // A row whose egg variable is gone carries nothing over.
            $variable = $serverVariable->getRelationValue('variable');
            if ($variable instanceof EggVariable) {
                $current[$variable->env_variable] = (string) $serverVariable->variable_value;
            }
        }

        $values = [];
        foreach (EggVariable::query()->where('egg_id', $egg->id)->get() as $variable) {
            $values[$variable->env_variable] = $versions[$variable->env_variable] ?? $current[$variable->env_variable] ?? (string) $variable->default_value;
        }

        return $values;
    }

    /**
     * The checks run right before the egg changes (and already at the snapshot): the egg is
     * still this loader's official egg, the image is still one it offers, and every value
     * the server will carry over or get passes the new egg's rules.
     *
     * @param  array<string, string>  $versions
     *
     * @throws StepFailedException
     */
    public static function checkTarget(Server $server, Egg $egg, string $loader, string $image, array $versions): void
    {
        if (ServerTarget::eggLoader($egg) !== $loader || EggVariableMap::loaderFor($egg->uuid, $egg->update_url) !== $loader) {
            throw new StepFailedException('egg_unknown', $egg->name);
        }

        if (!in_array($image, array_values($egg->docker_images ?? []), true)) {
            // The egg, not the image (images are only shown with startup.read).
            throw new StepFailedException('no_java_image', $egg->name);
        }

        self::checkVariables($egg, $versions);
        self::checkVariables($egg, self::targetValues($server, $egg, $versions));
    }

    /**
     * Validates each value against the egg variable's own rules.
     *
     * @param  array<string, string>  $variables  env variable => value
     * @return array<string, EggVariable>
     *
     * @throws StepFailedException
     */
    public static function checkVariables(Egg $egg, array $variables): array
    {
        $eggVariables = EggVariable::query()->where('egg_id', $egg->id)->whereIn('env_variable', array_keys($variables))->get()->keyBy('env_variable');

        $checked = [];
        foreach ($variables as $env => $value) {
            $variable = $eggVariables->get($env);
            if (!$variable instanceof EggVariable) {
                throw new StepFailedException('egg_variables_missing', $egg->name.': '.$env);
            }

            if (Validator::make(['value' => $value], ['value' => $variable->rules ?? []])->fails()) {
                // The name only: the error is shown to every viewer, and the value may be a secret.
                throw new StepFailedException('variable_invalid', $env);
            }

            $checked[$env] = $variable;
        }

        return $checked;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if ($install->progressValue('egg_changed')) {
            return StepResult::done();
        }

        $switch = $install->loader_switch ?? [];
        $snapshot = $install->snapshot ?? [];
        $variables = is_array($switch['variables'] ?? null) ? array_map('strval', $switch['variables']) : null;
        $image = $switch['image'] ?? null;
        if ($variables === null || !is_string($image) || !isset($snapshot['egg_id'])) {
            throw new StepFailedException('unexpected', 'switch target missing');
        }

        $egg = Egg::query()->find((int) ($switch['egg_id'] ?? 0));
        if ($egg === null) {
            throw new StepFailedException('egg_missing', LoaderEggResolver::loaderName((string) ($switch['loader'] ?? '')));
        }

        $from = (string) ($snapshot['egg_name'] ?? '');
        $loader = (string) ($switch['loader'] ?? '');

        DB::transaction(function () use ($server, $egg, $snapshot, $variables, $image, $loader) {
            /** @var Server $locked */
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            // Someone else moved the server meanwhile: never stack a second change on top.
            if ($locked->egg_id !== (int) $snapshot['egg_id'] && $locked->egg_id !== $egg->id) {
                throw new StepFailedException('egg_changed_elsewhere', (string) $locked->egg_id);
            }

            // Re-checked at change time: the egg may have been edited since the snapshot.
            self::checkTarget($locked, $egg, $loader, $image, $variables);
            $eggVariables = self::checkVariables($egg, $variables);

            // EggChangerService walks these; loaded up front instead of one query per variable.
            $locked->load(['egg', 'serverVariables.variable', 'mounts']);
            app(EggChangerService::class)->handle($locked, $egg);

            foreach ($eggVariables as $env => $variable) {
                ServerVariable::query()->updateOrCreate(
                    ['server_id' => $locked->id, 'variable_id' => $variable->id],
                    ['variable_value' => $variables[$env]],
                );
            }

            $locked->forceFill(['image' => $image])->save();
        });

        $install->setProgress('egg_changed', true);
        $install->addLog('info', 'egg_changed', ['from' => $from, 'to' => $egg->name]);

        return StepResult::done();
    }
}
