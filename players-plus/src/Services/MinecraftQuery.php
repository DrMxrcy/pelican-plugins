<?php

namespace DrMxrcy\PlayersPlus\Services;

use DrMxrcy\PlayersPlus\Services\Query\Channel;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Services\Query\PingResult;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;
use DrMxrcy\PlayersPlus\Services\Query\QueryResult;
use DrMxrcy\PlayersPlus\Services\Query\SocketConnector;

/**
 * In-house Minecraft Java client for Server List Ping (TCP, 1.7+) and Query (UDP, GameSpy4
 * full stat). Only the poll job calls it; pages read stored samples.
 */
class MinecraftQuery
{
    /** Status JSON with a large favicon is ~50 KB; anything past this is not a status answer. */
    public const MAX_PACKET_BYTES = 1_048_576;

    // Java names, optionally with the Floodgate/Geyser prefix (`.` or `*`) for Bedrock players.
    private const NAME_PATTERN = '/^[.*]?[A-Za-z0-9_]{1,16}$/D';

    /** Names kept per poll; a hostile server can send any number. */
    public const MAX_PLAYERS = 200;

    /** Counts are stored as unsigned 32-bit ints; clamp below the signed limit. */
    public const MAX_COUNT = 2_147_483_647;

    private const NIL_UUID = '00000000000000000000000000000000';

    public function __construct(private readonly Connector $connector = new SocketConnector()) {}

    /** @throws QueryException */
    public function ping(string $host, int $port, float $timeout): PingResult
    {
        $channel = $this->connector->open(ServerAddress::uri('tcp', $host, $port), $timeout);

        try {
            // Handshake (protocol -1 = "just asking for status", next state 1 = status), then status request.
            $channel->write(self::frame("\x00" . self::varint(-1) . self::string($host) . pack('n', $port) . self::varint(1)));
            $channel->write(self::frame("\x00"));

            $length = self::readVarint($channel);
            if ($length < 1 || $length > self::MAX_PACKET_BYTES) {
                throw new QueryException("bad status frame length $length");
            }

            $body = $channel->read($length);
            $offset = 0;
            if (self::varintAt($body, $offset) !== 0) {
                throw new QueryException('unexpected packet id');
            }

            $jsonLength = self::varintAt($body, $offset);
            if ($offset + $jsonLength > strlen($body)) {
                throw new QueryException('truncated status json');
            }

            return self::parseStatusJson(substr($body, $offset, $jsonLength));
        } finally {
            $channel->close();
        }
    }

    /** @throws QueryException */
    public function query(string $host, int $port, float $timeout): QueryResult
    {
        $channel = $this->connector->open(ServerAddress::uri('udp', $host, $port), $timeout);

        try {
            $session = pack('N', random_int(0, PHP_INT_MAX) & 0x0F0F0F0F);

            $channel->write("\xFE\xFD\x09" . $session);
            $answer = $channel->receive();
            if (strlen($answer) < 6 || $answer[0] !== "\x09" || substr($answer, 1, 4) !== $session) {
                throw new QueryException('bad handshake answer');
            }

            $token = rtrim(substr($answer, 5), "\x00");
            if (preg_match('/^-?\d{1,10}$/', $token) !== 1) {
                throw new QueryException('bad challenge token');
            }

            $channel->write("\xFE\xFD\x00" . $session . pack('N', (int) $token & 0xFFFFFFFF) . "\x00\x00\x00\x00");

            return self::parseFullStat($channel->receive(), $session);
        } finally {
            $channel->close();
        }
    }

    /** @throws QueryException */
    public static function parseStatusJson(string $json): PingResult
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new QueryException('status is not a JSON object');
        }

        $players = is_array($data['players'] ?? null) ? $data['players'] : [];
        $online = $players['online'] ?? 0;
        $max = $players['max'] ?? 0;
        if (!is_int($online) || !is_int($max) || $online < 0 || $max < 0) {
            throw new QueryException('bad player count');
        }

        $sample = [];
        foreach (is_array($players['sample'] ?? null) ? $players['sample'] : [] as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (!is_string($name) || !self::isPlayerName($name) || isset($sample[$name])) {
                continue;
            }

            $sample[$name] = ['name' => $name, 'uuid' => self::normalizeUuid($entry['id'] ?? null)];
            if (count($sample) === self::MAX_PLAYERS) {
                break;
            }
        }

        $version = $data['version']['name'] ?? null;

        return new PingResult(min($online, self::MAX_COUNT), min($max, self::MAX_COUNT), array_values($sample), is_string($version) ? $version : null);
    }

    /** @throws QueryException */
    public static function parseFullStat(string $packet, string $session): QueryResult
    {
        if (strlen($packet) < 16 || $packet[0] !== "\x00" || substr($packet, 1, 4) !== $session) {
            throw new QueryException('bad full stat header');
        }

        if (substr($packet, 5, 11) !== "splitnum\x00\x80\x00") {
            throw new QueryException('bad full stat padding');
        }

        $split = strpos($packet, "\x00\x01player_\x00\x00", 16);
        if ($split === false) {
            throw new QueryException('full stat has no player section');
        }

        $kv = [];
        $parts = explode("\x00", substr($packet, 16, $split - 16));
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            if ($parts[$i] === '') {
                break;
            }
            $kv[$parts[$i]] = $parts[$i + 1];
        }

        $online = $kv['numplayers'] ?? '';
        $max = $kv['maxplayers'] ?? '';
        if (!ctype_digit($online) || !ctype_digit($max)) {
            throw new QueryException('bad player count');
        }

        $names = [];
        foreach (explode("\x00", substr($packet, $split + 11)) as $name) {
            if (self::isPlayerName($name)) {
                $names[$name] = true;
                if (count($names) === self::MAX_PLAYERS) {
                    break;
                }
            }
        }

        return new QueryResult(
            self::clampCount($online),
            self::clampCount($max),
            array_map('strval', array_keys($names)),
            isset($kv['version']) && $kv['version'] !== '' ? $kv['version'] : null,
        );
    }

    /** A decimal string of any length as a count no larger than MAX_COUNT. */
    private static function clampCount(string $digits): int
    {
        $digits = ltrim($digits, '0');

        return strlen($digits) > 10 ? self::MAX_COUNT : min((int) $digits, self::MAX_COUNT);
    }

    public static function isPlayerName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** Dashed lowercase UUID, or null for a missing, malformed or nil id. */
    public static function normalizeUuid(mixed $id): ?string
    {
        if (!is_string($id)) {
            return null;
        }

        $hex = strtolower(str_replace('-', '', $id));
        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1 || $hex === self::NIL_UUID) {
            return null;
        }

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function frame(string $payload): string
    {
        return self::varint(strlen($payload)) . $payload;
    }

    private static function string(string $value): string
    {
        return self::varint(strlen($value)) . $value;
    }

    private static function varint(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $out;
    }

    /** @throws QueryException */
    private static function readVarint(Channel $channel): int
    {
        $bytes = '';
        do {
            if (strlen($bytes) === 5) {
                throw new QueryException('varint too long');
            }
            $byte = $channel->read(1);
            $bytes .= $byte;
        } while (ord($byte) & 0x80);

        $offset = 0;

        return self::varintAt($bytes, $offset);
    }

    /** @throws QueryException */
    private static function varintAt(string $buffer, int &$offset): int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($offset >= strlen($buffer)) {
                throw new QueryException('truncated varint');
            }
            $byte = ord($buffer[$offset++]);
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                // Every varint read here is a length or packet id; a sign bit means garbage.
                if ($value > 0x7FFFFFFF) {
                    throw new QueryException('negative varint');
                }

                return $value;
            }
        }

        throw new QueryException('varint too long');
    }
}
