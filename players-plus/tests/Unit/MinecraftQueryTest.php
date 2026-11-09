<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Services\MinecraftQuery;
use DrMxrcy\PlayersPlus\Services\Query\QueryException;
use DrMxrcy\PlayersPlus\Services\Query\SocketConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeChannel;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\Packets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Server List Ping and Query against fixture packets. No real game server is contacted.
 */
class MinecraftQueryTest extends TestCase
{
    // ---- Server List Ping ----------------------------------------------------------------

    public function test_ping_sends_handshake_and_status_request_and_parses_the_response(): void
    {
        $channel = new FakeChannel(Packets::statusResponse(Packets::vanillaStatusJson()));
        $connector = new FakeConnector(['tcp://10.0.0.5:25566' => $channel]);

        $result = (new MinecraftQuery($connector))->ping('10.0.0.5', 25566, 3.0);

        $this->assertSame(2, $result->online);
        $this->assertSame(20, $result->max);
        $this->assertSame('1.21.4', $result->version);
        $this->assertSame([
            ['name' => 'Notch', 'uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'],
            ['name' => 'jeb_', 'uuid' => '853c80ef-3c37-49fd-aa49-938b674adae6'],
        ], $result->players);

        // Handshake: id 0, protocol -1 (status only), host, port 25566 big-endian, next state 1.
        $handshake = "\x00" . "\xFF\xFF\xFF\xFF\x0F" . "\x0810.0.0.5" . "\x63\xDE" . "\x01";
        $this->assertSame([
            Packets::varint(strlen($handshake)) . $handshake,
            "\x01\x00",
        ], $channel->written);
        $this->assertTrue($channel->closed);
        $this->assertSame(3.0, $connector->opened[0]['timeout']);
    }

    public function test_ping_brackets_ipv6_in_the_uri_but_not_in_the_handshake(): void
    {
        $channel = new FakeChannel(Packets::statusResponse(Packets::vanillaStatusJson()));
        $connector = new FakeConnector(['tcp://[2001:db8::1]:25565' => $channel]);

        (new MinecraftQuery($connector))->ping('2001:db8::1', 25565, 3.0);

        $this->assertStringContainsString("\x0B2001:db8::1", $channel->written[0]);
    }

    public function test_status_json_with_a_chat_component_description_and_no_sample(): void
    {
        $result = MinecraftQuery::parseStatusJson((string) json_encode([
            'version' => ['name' => 'Paper 1.20.6', 'protocol' => 766],
            'players' => ['max' => 50, 'online' => 30],
            'description' => ['extra' => [['text' => 'hi']], 'text' => ''],
            'favicon' => 'data:image/png;base64,' . str_repeat('A', 5000),
        ]));

        $this->assertSame(30, $result->online);
        $this->assertSame(50, $result->max);
        $this->assertSame([], $result->players);
    }

    /** Servers put MOTD lines or "Anonymous Player" in the sample; those are not players. */
    public function test_sample_entries_that_are_not_real_players_are_dropped(): void
    {
        $result = MinecraftQuery::parseStatusJson((string) json_encode([
            'players' => ['max' => 10, 'online' => 3, 'sample' => [
                ['name' => '§aWelcome to the server!', 'id' => '00000000-0000-0000-0000-000000000000'],
                ['name' => 'Anonymous Player', 'id' => '00000000-0000-0000-0000-000000000000'],
                ['name' => 'Steve', 'id' => '00000000-0000-0000-0000-000000000000'],
                ['name' => 'Alex', 'id' => 'not-a-uuid'],
                ['name' => 'Bob'],
                'garbage',
            ]],
        ]));

        $this->assertSame([
            ['name' => 'Steve', 'uuid' => null],
            ['name' => 'Alex', 'uuid' => null],
            ['name' => 'Bob', 'uuid' => null],
        ], $result->players);
    }

    /** @return array<string, array{string}> */
    public static function badStatusJson(): array
    {
        return [
            'not json' => ['<html>'],
            'not an object' => ['"hello"'],
            'negative count' => ['{"players":{"online":-1,"max":20}}'],
            'string count' => ['{"players":{"online":"2","max":20}}'],
        ];
    }

    #[DataProvider('badStatusJson')]
    public function test_bad_status_json_throws(string $json): void
    {
        $this->expectException(QueryException::class);

        MinecraftQuery::parseStatusJson($json);
    }

    public function test_ping_rejects_a_wrong_packet_id(): void
    {
        $json = Packets::vanillaStatusJson();
        $body = "\x01" . Packets::varint(strlen($json)) . $json;
        $channel = new FakeChannel(Packets::varint(strlen($body)) . $body);

        $this->expectException(QueryException::class);

        (new MinecraftQuery(new FakeConnector(['tcp://h:1' => $channel])))->ping('h', 1, 3.0);
    }

    public function test_ping_rejects_an_oversized_frame_without_reading_it(): void
    {
        $channel = new FakeChannel(Packets::varint(MinecraftQuery::MAX_PACKET_BYTES + 1));

        try {
            (new MinecraftQuery(new FakeConnector(['tcp://h:1' => $channel])))->ping('h', 1, 3.0);
            $this->fail('expected QueryException');
        } catch (QueryException) {
            $this->assertTrue($channel->closed);
        }
    }

    public function test_ping_rejects_a_truncated_response(): void
    {
        $frame = Packets::statusResponse(Packets::vanillaStatusJson());
        $channel = new FakeChannel(substr($frame, 0, -10));

        $this->expectException(QueryException::class);

        (new MinecraftQuery(new FakeConnector(['tcp://h:1' => $channel])))->ping('h', 1, 3.0);
    }

    public function test_ping_rejects_a_varint_longer_than_five_bytes(): void
    {
        $channel = new FakeChannel(str_repeat("\xFF", 6));

        $this->expectException(QueryException::class);

        (new MinecraftQuery(new FakeConnector(['tcp://h:1' => $channel])))->ping('h', 1, 3.0);
    }

    // ---- Query ---------------------------------------------------------------------------

    public function test_query_handshake_then_full_stat(): void
    {
        $channel = new FakeChannel(responder: function (string $request): string {
            $session = substr($request, 3, 4);

            return $request[2] === "\x09"
                ? Packets::handshakeResponse($session, '-9513307')
                : Packets::fullStatResponse($session, ['Notch', 'jeb_', 'Dinnerbone']);
        });
        $connector = new FakeConnector(['udp://10.0.0.5:25575' => $channel]);

        $result = (new MinecraftQuery($connector))->query('10.0.0.5', 25575, 3.0);

        $this->assertSame(3, $result->online);
        $this->assertSame(20, $result->max);
        $this->assertSame(['Notch', 'jeb_', 'Dinnerbone'], $result->names);
        $this->assertSame('1.21.4', $result->version);

        [$handshake, $fullStat] = $channel->written;
        $session = substr($handshake, 3, 4);
        $this->assertSame("\xFE\xFD\x09" . $session, $handshake);
        $this->assertSame(0, unpack('N', $session)[1] & 0xF0F0F0F0, 'session id must be masked with 0x0F0F0F0F');
        // Challenge token -9513307 as a big-endian int32, then 4 bytes of padding (full stat).
        $this->assertSame("\xFE\xFD\x00" . $session . pack('N', -9513307 & 0xFFFFFFFF) . "\x00\x00\x00\x00", $fullStat);
        $this->assertTrue($channel->closed);
    }

    public function test_full_stat_example_from_the_protocol_docs(): void
    {
        $result = MinecraftQuery::parseFullStat((string) hex2bin(Packets::DOCS_FULL_STAT_HEX), "\x00\x00\x00\x01");

        $this->assertSame(2, $result->online);
        $this->assertSame(20, $result->max);
        $this->assertSame(['barneygale', 'Vivalahelvig'], $result->names);
        $this->assertSame('Beta 1.9 Prerelease 4', $result->version);
    }

    public function test_full_stat_with_nobody_online(): void
    {
        $result = MinecraftQuery::parseFullStat(Packets::fullStatResponse("\x01\x02\x03\x04", []), "\x01\x02\x03\x04");

        $this->assertSame(0, $result->online);
        $this->assertSame([], $result->names);
    }

    public function test_full_stat_keeps_names_the_server_reports_but_drops_invalid_ones(): void
    {
        $packet = Packets::fullStatResponse("\x01\x02\x03\x04", ['Notch', "bad\x01name", 'way_too_long_for_minecraft_name']);

        $result = MinecraftQuery::parseFullStat($packet, "\x01\x02\x03\x04");

        $this->assertSame(['Notch'], $result->names);
        $this->assertSame(3, $result->online, 'the count is what the server reports');
    }

    /** @return array<string, array{string}> */
    public static function badFullStat(): array
    {
        $session = "\x01\x02\x03\x04";
        $good = Packets::fullStatResponse($session, ['Notch']);

        return [
            'empty' => [''],
            'wrong type' => ["\x09" . substr($good, 1)],
            'wrong session' => [substr_replace($good, "\x09\x09\x09\x09", 1, 4)],
            'no splitnum padding' => [substr($good, 0, 5) . 'hostname' . "\x00x\x00\x00"],
            'no player section' => [substr($good, 0, (int) strpos($good, "\x01player_"))],
            'non numeric count' => [Packets::fullStatResponse($session, [], ['numplayers' => 'many'])],
        ];
    }

    #[DataProvider('badFullStat')]
    public function test_bad_full_stat_throws(string $packet): void
    {
        $this->expectException(QueryException::class);

        MinecraftQuery::parseFullStat($packet, "\x01\x02\x03\x04");
    }

    public function test_query_rejects_a_bad_challenge_token(): void
    {
        $channel = new FakeChannel(responder: fn (string $request) => "\x09" . substr($request, 3, 4) . "abc\x00");

        $this->expectException(QueryException::class);

        (new MinecraftQuery(new FakeConnector(['udp://h:1' => $channel])))->query('h', 1, 3.0);
    }

    public function test_query_without_an_answer_throws_and_closes(): void
    {
        $channel = new FakeChannel();

        try {
            (new MinecraftQuery(new FakeConnector(['udp://h:1' => $channel])))->query('h', 1, 3.0);
            $this->fail('expected QueryException');
        } catch (QueryException) {
            $this->assertTrue($channel->closed);
        }
    }

    // ---- Real sockets (local only) -------------------------------------------------------

    public function test_a_refused_local_connection_fails_fast_with_a_query_exception(): void
    {
        // Bind then close a listener so the port is known to be closed.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $started = microtime(true);
        try {
            (new MinecraftQuery(new SocketConnector()))->ping('127.0.0.1', $port, 1.0);
            $this->fail('expected QueryException');
        } catch (QueryException) {
            $this->assertLessThan(2.0, microtime(true) - $started);
        }
    }

    public function test_a_silent_local_udp_port_times_out(): void
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $started = microtime(true);
        try {
            (new MinecraftQuery(new SocketConnector()))->query('127.0.0.1', $port, 0.5);
            $this->fail('expected QueryException');
        } catch (QueryException) {
            $this->assertLessThan(1.5, microtime(true) - $started);
        } finally {
            fclose($server);
        }
    }
}
