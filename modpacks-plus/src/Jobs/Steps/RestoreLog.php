<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\PreservedFiles;

/** Log lines for one restored entry (shared by the switch step and "Restore previous files"). */
final class RestoreLog
{
    public static function add(PackInstall $install, string $result, string $entry, string $replaced): void
    {
        match ($result) {
            PreservedFiles::RESULT_MOVED => $install->addLog('info', 'restored', ['path' => $entry]),
            PreservedFiles::RESULT_REPLACED => $install->addLog('warning', 'restored_replaced', ['path' => $entry, 'to' => "$replaced/$entry"]),
            PreservedFiles::RESULT_ABSENT => $install->addLog('warning', 'restore_absent', ['path' => $entry]),
            default => null,
        };
    }
}
