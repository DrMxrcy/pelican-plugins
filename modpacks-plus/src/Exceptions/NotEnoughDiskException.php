<?php

namespace DrMxrcy\ModpacksPlus\Exceptions;

/**
 * Plan 38: the pack doesn't fit the server's free disk space (minus headroom). `detail` is
 * "needed / free / limit" in human sizes (DiskBudget::detail()); `packBytes` is the pack's
 * validated total, so callers can remember it without parsing the message.
 */
class NotEnoughDiskException extends UnsafePlanException
{
    public function __construct(string $detail, public readonly int $packBytes)
    {
        parent::__construct('not_enough_disk', $detail);
    }
}
