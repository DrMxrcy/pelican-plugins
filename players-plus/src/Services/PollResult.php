<?php

namespace DrMxrcy\PlayersPlus\Services;

use DrMxrcy\PlayersPlus\Enums\SampleStatus;

final class PollResult
{
    /**
     * @param  list<array{name: string, uuid: string|null}>  $players
     * @param  string|null  $error  why the server was unreachable, for the log only
     */
    public function __construct(
        public readonly SampleStatus $status,
        public readonly ?int $online = null,
        public readonly ?int $max = null,
        public readonly array $players = [],
        public readonly ?string $source = null,
        public readonly ?string $error = null,
    ) {}

    public static function offline(): self
    {
        return new self(SampleStatus::Offline);
    }

    public static function unreachable(string $error): self
    {
        return new self(SampleStatus::Unreachable, error: $error);
    }
}
