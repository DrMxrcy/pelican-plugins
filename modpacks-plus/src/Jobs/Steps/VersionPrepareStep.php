<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;

/**
 * Plan 40, a version change (no pack): before anything on the server changes, checks the
 * server is still a Minecraft server and the chosen versions are ones the loader egg takes,
 * and finds the worlds every later step protects (as PrepareStep does for a pack). Only reads.
 */
class VersionPrepareStep extends InstallStep
{
    public const KEY = 'version_prepare';

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        if (is_array($install->progressValue('worlds'))) {
            return StepResult::done();
        }

        $switch = $install->loader_switch ?? [];
        $loader = (string) ($switch['loader'] ?? '');
        $minecraft = (string) ($switch['minecraft'] ?? '');
        if (!$install->isVersionChange() || !ServerTarget::isMinecraft($server) || $loader === '' || $minecraft === '') {
            throw new StepFailedException('server_not_supported');
        }

        try {
            EggVariableMap::variables($loader, $minecraft, is_string($switch['loader_version'] ?? null) ? $switch['loader_version'] : null);
        } catch (InstallRefusedException $exception) {
            throw new StepFailedException($exception->reason, $exception->detail);
        }

        $install->setProgress('worlds', PrepareStep::findWorlds($this->files(), $server));

        $from = is_array($switch['from'] ?? null) ? $switch['from'] : [];
        $install->addLog('info', 'version_prepared', [
            'from' => self::label($from['loader'] ?? null, $from['minecraft'] ?? null),
            'to' => self::label($loader, $minecraft),
        ]);
        if (!empty($switch['keep_mods'])) {
            $install->addLog('warning', 'version_mods_kept', []);
        }

        return StepResult::done();
    }

    /** "Fabric 26.2", with "?" for what is unknown. */
    public static function label(mixed $loader, mixed $minecraft): string
    {
        return (is_string($loader) && $loader !== '' ? LoaderEggResolver::loaderName($loader) : '?').' '.(is_string($minecraft) && $minecraft !== '' ? $minecraft : '?');
    }
}
