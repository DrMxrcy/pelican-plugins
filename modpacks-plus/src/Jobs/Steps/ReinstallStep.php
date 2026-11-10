<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use App\Enums\ServerState;
use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\ServerAvailability;
use Illuminate\Support\Carbon;

/**
 * Loader switch: asks the node to reinstall the server with the panel's
 * ReinstallServerService (one daemon call). The request time is stored first, so a
 * retried job sees that the reinstall already started (server installing, or installed
 * since) and does not start a second one. At most MAX_ATTEMPTS requests.
 */
class ReinstallStep extends InstallStep
{
    public const KEY = 'reinstall';

    public const MAX_ATTEMPTS = 3;

    public const RETRY_SECONDS = 15;

    protected const TOUCHES_FILES = false;

    public static function cancellableAt(PackInstall $install): bool
    {
        return false;
    }

    /** Whether the server shows that the reinstall requested at $requested was taken up. */
    public static function started(Server $server, Carbon $requested): bool
    {
        return in_array($server->status, [ServerState::Installing, ServerState::InstallFailed, ServerState::ReinstallFailed], true)
            || ($server->installed_at !== null && $server->installed_at->gte($requested->copy()->subSeconds(5)));
    }

    protected function perform(PackInstall $install, Server $server, InstallEngine $engine): StepResult
    {
        $server->refresh();

        $requested = $install->progressValue('reinstall_requested_at');
        if (is_string($requested) && self::started($server, Carbon::parse($requested))) {
            return StepResult::done();
        }

        $attempts = (int) $install->progressValue('reinstall_attempts', 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new StepFailedException('reinstall_not_started', (string) $attempts);
        }

        // Plan 39: a failed (re)install is what this reinstall repairs, so only busy states
        // are waited out; a suspension never clears by itself.
        $stuck = ServerAvailability::stuck($server);
        if ($stuck === ServerAvailability::SUSPENDED) {
            throw new StepFailedException('server_unavailable', $stuck);
        }
        if ($server->isInConflictState() && $stuck === null) {
            if (!$install->progressValue('conflict_logged_'.self::KEY)) {
                $install->setProgress('conflict_logged_'.self::KEY, true);
                $install->addLog('warning', 'waiting_conflict', ['step' => self::KEY]);
            }

            return StepResult::again(30);
        }

        $install->setProgress('reinstall_requested_at', now()->toIso8601String());
        $install->setProgress('reinstall_attempts', $attempts + 1);
        $engine->persist($install);

        try {
            app(ReinstallServerService::class)->handle($server);
        } catch (\Throwable $exception) {
            report($exception);
            $install->addLog('warning', 'reinstall_retry', ['detail' => class_basename($exception)]);

            return StepResult::again(self::RETRY_SECONDS);
        }

        $install->addLog('info', 'reinstall_started', []);

        return StepResult::done();
    }
}
