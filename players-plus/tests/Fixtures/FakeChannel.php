<?php

namespace DrMxrcy\PlayersPlus\Tests\Fixtures;

use DrMxrcy\PlayersPlus\Services\Query\Channel;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;

/**
 * Scripted channel. TCP: read() serves $stream byte by byte. UDP: receive() answers each
 * written datagram through $responder (it sees what was sent, e.g. the session id).
 */
final class FakeChannel implements Channel
{
    /** @var list<string> */
    public array $written = [];

    public bool $closed = false;

    /** @param  (callable(string): string)|null  $responder */
    public function __construct(private string $stream = '', private $responder = null) {}

    public function write(string $bytes): void
    {
        $this->written[] = $bytes;
    }

    public function read(int $length): string
    {
        if (strlen($this->stream) < $length) {
            throw new QueryException('timed out');
        }

        $chunk = substr($this->stream, 0, $length);
        $this->stream = substr($this->stream, $length);

        return $chunk;
    }

    public function receive(): string
    {
        if ($this->responder === null || $this->written === []) {
            throw new QueryException('timed out');
        }

        return ($this->responder)($this->written[array_key_last($this->written)]);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
