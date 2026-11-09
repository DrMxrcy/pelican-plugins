<?php

namespace DrMxrcy\PlayersPlus\Services;

use DrMxrcy\PlayersPlus\Enums\LogEventType;

/** A join, leave or UUID line from the server log. Names are validated; UUIDs dashed lowercase. */
final class LogEvent
{
    public function __construct(
        public readonly LogEventType $type,
        public readonly string $name,
        public readonly ?string $uuid = null,
    ) {}
}
