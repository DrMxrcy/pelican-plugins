<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Moves the previous pack's folders into `.modpacks-plus/previous-<date>-<id>/`.
 * Nothing is deleted. A folder that is (or contains) a world or another protected
 * path is never moved. Idempotent: a folder already moved is not at the root anymore.
 */
class MoveAsideStep extends InstallStep
{
    public const KEY = 'move_aside';

    public const FOLDERS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts'];

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $validator = $engine->validatorFor($install);
        $files = $this->files();

        if ($install->moved_aside_path === null) {
            $install->moved_aside_path = PlanValidator::OWN_FOLDER.'/previous-'.now()->format('Ymd-His').'-'.$install->id;
            $engine->persist($install);
        }
        $aside = $install->moved_aside_path;

        $files->createDirectory($server, $aside);

        $root = $files->list($server, '') ?? [];
        $asideEntries = $files->list($server, $aside) ?? [];
        $moved = $install->progressValue('moved', []);

        foreach (self::FOLDERS as $folder) {
            if (!isset($root[$folder])) {
                continue;
            }

            if ($validator->isProtected($folder)) {
                $install->addLog('warning', 'kept_protected', ['path' => $folder]);

                continue;
            }

            if (isset($asideEntries[$folder])) {
                throw new StepFailedException('move_aside_conflict', "$aside/$folder");
            }

            $files->rename($server, $folder, "$aside/$folder");
            $moved[] = $folder;
            $install->setProgress('moved', array_values(array_unique($moved)));
            $install->addLog('info', 'moved_aside', ['path' => $folder, 'to' => "$aside/$folder"]);
            $engine->persist($install);
        }

        return StepResult::done();
    }
}
