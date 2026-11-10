<?php

namespace DrMxrcy\ModpacksPlus\Support;

use App\Enums\ServerState;
use App\Models\Server;
use DrMxrcy\ModpacksPlus\Models\PackInstall;

/**
 * Whether a server can take a pack install now (plan 39).
 *
 * Pelican's conflict states come in two kinds: busy ones that end by themselves (installing,
 * restoring a backup, a transfer, node maintenance) and stuck ones that never do (a failed
 * install or reinstall, a suspension). Waiting on a stuck one only holds an install until its
 * step deadline, so installs are refused up front and fail fast when the server gets stuck.
 * Busy states are still waited out (an install may be queued behind a backup restore).
 */
final class ServerAvailability
{
    public const FAILED_INSTALL = 'failed_install';

    public const SUSPENDED = 'suspended';

    /**
     * Whether $stuck still lets this install go on: a loader switch whose reinstall has not
     * been requested yet repairs a failed (re)install itself, so its steps up to the reinstall
     * run instead of waiting.
     */
    public static function switchRepairs(?string $stuck, PackInstall $install): bool
    {
        return $stuck === self::FAILED_INSTALL && $install->loader_switch !== null && $install->progressValue('reinstall_requested_at') === null;
    }

    /** Null unless the server is in a state that never clears without someone acting on it. */
    public static function stuck(Server $server): ?string
    {
        if ($server->isSuspended()) {
            return self::SUSPENDED;
        }

        $status = $server->status;

        return $status === ServerState::InstallFailed || $status === ServerState::ReinstallFailed
            ? self::FAILED_INSTALL
            : null;
    }
}
