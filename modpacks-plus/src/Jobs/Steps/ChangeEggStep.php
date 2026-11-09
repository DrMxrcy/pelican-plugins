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
                throw new StepFailedException('variable_invalid', "$env=$value");
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

        DB::transaction(function () use ($server, $egg, $snapshot, $variables, $image) {
            /** @var Server $locked */
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            // Someone else moved the server meanwhile: never stack a second change on top.
            if ($locked->egg_id !== (int) $snapshot['egg_id'] && $locked->egg_id !== $egg->id) {
                throw new StepFailedException('egg_changed_elsewhere', (string) $locked->egg_id);
            }

            $eggVariables = self::checkVariables($egg, $variables);

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
        $install->addLog('info', 'egg_changed', ['from' => $from, 'to' => $egg->name, 'image' => $image]);

        return StepResult::done();
    }
}
