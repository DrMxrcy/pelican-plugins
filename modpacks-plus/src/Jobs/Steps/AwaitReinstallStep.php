<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Enums\ServerState;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\LoaderLaunchFiles;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Loader switch: waits for the reinstall by polling `servers.status` (set by the node's
 * install callback), re-dispatching every 15 s. Null after the request: done.
 * install_failed / reinstall_failed: the install fails, worlds stay in the preserved
 * folder for "Restore previous files". The step deadline (20 min) ends any longer wait.
 *
 * Plan 43: a node can report an install script that failed as a successful install, so a
 * reported success is only taken once the target loader's launch files are on the server
 * and written since the request (LoaderLaunchFiles, two listings at most, 120 s clock skew;
 * a file without a time counts, logged once). Missing: `reinstall_incomplete`, same
 * outcome as a failed reinstall. A daemon error while listing is retried within the deadline.
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

        if (!$install->progressValue('reinstall_reported')) {
            $install->setProgress('reinstall_reported', true);
            $install->addLog('info', 'reinstalled', []);
        }

        $loader = (string) ($install->loader_switch['loader'] ?? '');

        try {
            $result = LoaderLaunchFiles::check($this->files(), $server, $loader, self::jarFile($server), $requested->getTimestamp());
        } catch (Throwable $exception) {
            report($exception);
            if (!$install->progressValue('reinstall_check_logged')) {
                $install->setProgress('reinstall_check_logged', true);
                $install->addLog('warning', 'reinstall_check_retry', ['detail' => class_basename($exception)]);
            }

            return StepResult::again(self::POLL_SECONDS);
        }

        if ($result['missing'] !== null) {
            throw new StepFailedException('reinstall_incomplete', $result['missing']);
        }

        if ($result['undated'] && !$install->progressValue('reinstall_check_undated')) {
            // The node gave no modification time: whether the reinstall wrote the file is unknown.
            $install->setProgress('reinstall_check_undated', true);
            $install->addLog('warning', 'reinstall_check_undated', []);
        }

        return StepResult::done();
    }

    /** The server's SERVER_JARFILE value (or the egg's default), when its egg has one. */
    private static function jarFile(Server $server): ?string
    {
        $variable = $server->variables()->where('egg_variables.env_variable', 'SERVER_JARFILE')->first();
        if ($variable === null) {
            return null;
        }

        $value = $variable->getAttribute('server_value') ?? $variable->default_value;

        return is_string($value) ? $value : null;
    }
}
