<?php

namespace DrMxrcy\PlayersPlus\Tests\Fixtures;

/**
 * Wire-format fixtures for Server List Ping (TCP) and Query (UDP), byte for byte as a
 * vanilla server sends them (see the Minecraft protocol docs, "Server List Ping" and "Query").
 */
final class Packets
{
    public static function varint(int $value): string
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

    /** A status response frame: length, packet id 0x00, JSON string. */
    public static function statusResponse(string $json): string
    {
        $body = "\x00" . self::varint(strlen($json)) . $json;

        return self::varint(strlen($body)) . $body;
    }

    /** The JSON a vanilla 1.21 server answers with: 2 of 20 online, both in the sample. */
    public static function vanillaStatusJson(): string
    {
        return (string) json_encode([
            'version' => ['name' => '1.21.4', 'protocol' => 769],
            'players' => [
                'max' => 20,
                'online' => 2,
                'sample' => [
                    ['name' => 'Notch', 'id' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'],
                    ['name' => 'jeb_', 'id' => '853c80ef3c3749fdaa49938b674adae6'],
                ],
            ],
            'description' => ['text' => 'A Minecraft Server'],
            'enforcesSecureChat' => true,
        ]);
    }

    /** Query handshake answer: type 9, session id, challenge token as a NUL-terminated decimal. */
    public static function handshakeResponse(string $sessionId, string $token): string
    {
        return "\x09" . $sessionId . $token . "\x00";
    }

    /**
     * Full stat answer: type 0, session id, "splitnum" padding, K/V section, "player_" padding,
     * one NUL-terminated name each, closing NUL.
     *
     * @param  list<string>  $players
     * @param  array<string, string>  $overrides
     */
    public static function fullStatResponse(string $sessionId, array $players, array $overrides = []): string
    {
        $kv = array_merge([
            'hostname' => 'A Minecraft Server',
            'gametype' => 'SMP',
            'game_id' => 'MINECRAFT',
            'version' => '1.21.4',
            'plugins' => '',
            'map' => 'world',
            'numplayers' => (string) count($players),
            'maxplayers' => '20',
            'hostport' => '25565',
            'hostip' => '127.0.1.1',
        ], $overrides);

        $packet = "\x00" . $sessionId . "splitnum\x00\x80\x00";
        foreach ($kv as $key => $value) {
            $packet .= $key . "\x00" . $value . "\x00";
        }
        $packet .= "\x00\x01player_\x00\x00";
        foreach ($players as $name) {
            $packet .= $name . "\x00";
        }

        return $packet . "\x00";
    }

    /** The full stat example from the protocol docs, as raw hex: 2 of 20, barneygale and Vivalahelvig. */
    public const DOCS_FULL_STAT_HEX = '0000000001'
        . '73706c69746e756d008000'
        . '686f73746e616d6500'.'41204d696e6563726166742053657276657200'
        . '67616d657479706500'.'534d5000'
        . '67616d655f6964004d494e45435241465400'
        . '76657273696f6e00'.'4265746120312e392050726572656c656173652034'.'00'
        . '706c7567696e730000'
        . '6d617000'.'776f726c6400'
        . '6e756d706c617965727300'.'3200'
        . '6d6178706c617965727300'.'323000'
        . '686f7374706f727400'.'323535363500'
        . '686f7374697000'.'3132372e302e312e3100'
        . '00'
        . '01706c617965725f0000'
        . '6261726e657967616c6500'
        . '566976616c6168656c76696700'
        . '00';
}
