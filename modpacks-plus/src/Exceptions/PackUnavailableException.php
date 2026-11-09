<?php

namespace DrMxrcy\ModpacksPlus\Exceptions;

use RuntimeException;

/**
 * The pack source could not be read (API down, unknown pack/version, malformed index).
 * `reason` is a stable key (lang: modpacks-plus::strings.reasons.<reason>).
 */
class PackUnavailableException extends RuntimeException
{
    public readonly string $detail;

    public function __construct(public readonly string $reason, string $detail = '', ?\Throwable $previous = null)
    {
        $this->detail = UnsafePlanException::clean($detail);

        parent::__construct("Modpack unavailable ($reason)".($this->detail !== '' ? ": {$this->detail}" : ''), 0, $previous);
    }
}
