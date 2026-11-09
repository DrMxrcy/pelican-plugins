<?php

namespace DrMxrcy\PlayersPlus\Services;

use RuntimeException;

/** A moderation command was refused before sending, or the daemon did not accept it. */
final class ModerationException extends RuntimeException
{
    public const INVALID_NAME = 'invalid_name';

    public const DISABLED = 'disabled';

    public const OFFLINE = 'offline';

    public const FAILED = 'failed';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("Moderation command refused: $reason", 0, $previous);
    }
}
