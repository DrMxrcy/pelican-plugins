<?php

namespace DrMxrcy\PlayersPlus\Services\Query;

/**
 * A PHP stream socket with hard time limits: connecting and every read wait at most
 * $timeout seconds, and the whole conversation at most twice that, so a server that
 * trickles bytes can't hold a poll job.
 */
final class SocketChannel implements Channel
{
    private float $deadline;

    /** @param  resource  $socket */
    private function __construct(private $socket, private readonly float $timeout, float $deadline)
    {
        $this->deadline = $deadline;
    }

    public static function open(string $uri, float $timeout): self
    {
        // Connecting counts toward the overall budget too (DNS resolution happens before this
        // timeout applies and is not bounded by it).
        $deadline = microtime(true) + 2 * $timeout;
        $errno = 0;
        $error = '';
        // A refused or unreachable server is a normal poll outcome, not a PHP warning.
        set_error_handler(fn () => true);
        try {
            $socket = stream_socket_client($uri, $errno, $error, $timeout);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new QueryException("could not connect to $uri: " . ($error !== '' ? $error : "error $errno"));
        }

        return new self($socket, $timeout, $deadline);
    }

    public function write(string $bytes): void
    {
        $this->applyTimeout();

        if (@fwrite($this->socket, $bytes) !== strlen($bytes)) {
            throw new QueryException('write failed');
        }
    }

    public function read(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $this->applyTimeout();
            $chunk = @fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new QueryException(stream_get_meta_data($this->socket)['timed_out'] ? 'timed out' : 'connection closed');
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function receive(): string
    {
        $this->applyTimeout();
        $datagram = @fread($this->socket, 65535);

        if ($datagram === false || $datagram === '') {
            throw new QueryException('no answer');
        }

        return $datagram;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** Each wait is capped by both the per-step timeout and the remaining overall budget. */
    private function applyTimeout(): void
    {
        $left = min($this->timeout, $this->deadline - microtime(true));

        if ($left <= 0) {
            throw new QueryException('timed out');
        }

        stream_set_timeout($this->socket, (int) $left, (int) (fmod($left, 1) * 1_000_000));
    }
}
