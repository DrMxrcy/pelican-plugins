<?php

namespace DrMxrcy\ModpacksPlus\Exceptions;

use RuntimeException;

/** An install step stopped for a known reason (lang: modpacks-plus::strings.reasons.<reason>). */
class StepFailedException extends RuntimeException
{
    public readonly string $detail;

    public function __construct(public readonly string $reason, string $detail = '', ?\Throwable $previous = null)
    {
        $this->detail = UnsafePlanException::clean($detail);

        parent::__construct("Install step failed ($reason)".($this->detail !== '' ? ": {$this->detail}" : ''), 0, $previous);
    }
}
