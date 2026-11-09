<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

/** One open connection to a game server. Every method throws QueryException on failure or timeout. */
interface Channel
{
    public function write(string $bytes): void;

    /** Exactly $length bytes from a stream (TCP). */
    public function read(int $length): string;

    /** One datagram (UDP). */
    public function receive(): string;

    public function close(): void;
}
