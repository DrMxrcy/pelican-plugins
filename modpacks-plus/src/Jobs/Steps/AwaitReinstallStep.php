<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Enums\ServerState;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use Illuminate\Support\Carbon;

/**
 * Loader switch: waits for the reinstall by polling `servers.status` (set by the node's
 * install callback), re-dispatching every 15 s. Null after the request: done.
 * install_failed / reinstall_failed: the install fails, worlds stay in the preserved
 * folder for "Restore previous files". The step deadline (20 min) ends any longer wait.
 */
class AwaitReinstallStep extends InstallStep
{
    public const KEY = 'await_reinstall';

    public const POLL_SECONDS = 15;

    protected const TOUCHES_FILES = false;

    public static function cancellableAt(PackInstall $install): bool
    {
        return false;
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $server->refresh();

        $requested = $install->progressValue('reinstall_requested_at');
        if (!is_string($requested)) {
            throw new StepFailedException('reinstall_not_started', '0');
        }

        return match ($server->status) {
            ServerState::InstallFailed, ServerState::ReinstallFailed => throw new StepFailedException('reinstall_failed', $server->status->value),
            ServerState::Suspended => throw new StepFailedException('server_suspended'),
            null => $this->finished($install, $server, Carbon::parse($requested)),
            default => StepResult::again(self::POLL_SECONDS),
        };
    }

    private function finished(PackInstall $install, Server $server, Carbon $requested): StepResult
    {
        // Status null but no install since the request: the node has not picked it up yet.
        if ($server->installed_at === null || $server->installed_at->lt($requested->copy()->subSeconds(5))) {
            return StepResult::again(self::POLL_SECONDS);
        }

        $install->addLog('info', 'reinstalled', []);

        return StepResult::done();
    }
}
