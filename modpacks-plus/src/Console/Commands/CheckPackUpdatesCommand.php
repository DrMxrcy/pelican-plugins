<?php

namespace DrMxrcy\ModpacksPlus\Console\Commands;

use DrMxrcy\ModpacksPlus\Services\PackUpdateChecker;
use Illuminate\Console\Command;

/**
 * Scheduled daily or weekly (plugin setting "Check for pack updates"; off = never scheduled and
 * a manual run does nothing): marks installed packs with a newer version for their loader and
 * Minecraft version, and notifies each server's owner once per new version. Only reads pack
 * metadata from Modrinth/CurseForge; never downloads pack files and never contacts a node or
 * game server.
 */
class CheckPackUpdatesCommand extends Command
{
    protected $signature = 'modpacks-plus:check-updates';

    protected $description = 'Check installed Modpacks Plus packs for new versions and notify server owners';

    public function handle(PackUpdateChecker $checker): int
    {
        if (!PackUpdateChecker::enabled()) {
            $this->info('Modpacks Plus: update checks are off.');

            return self::SUCCESS;
        }

        $result = $checker->run();
        $this->info(sprintf('Modpacks Plus: %d packs checked, %d updates available, %d owners notified, %d skipped.', $result['checked'], $result['available'], $result['notified'], $result['skipped']));

        return self::SUCCESS;
    }
}
