<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use DrMxrcy\PlayersPlus\Services\ServerAddress;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerAddressTest extends TestCase
{
    /** @return array<string, array{?string, string, string, string}> */
    public static function addresses(): array
    {
        return [
            'alias wins when set' => ['play.example.com', '10.0.0.5', 'node.example.com', 'play.example.com'],
            'alias IP wins' => ['203.0.113.7', '10.0.0.5', 'node.example.com', '203.0.113.7'],
            'no alias: ip' => [null, '10.0.0.5', 'node.example.com', '10.0.0.5'],
            'blank alias: ip' => ['  ', '10.0.0.5', 'node.example.com', '10.0.0.5'],
            'unspecified alias: ip' => ['0.0.0.0', '10.0.0.5', 'node.example.com', '10.0.0.5'],
            '0.0.0.0: node fqdn' => [null, '0.0.0.0', 'node.example.com', 'node.example.com'],
            ':: node fqdn' => [null, '::', 'node.example.com', 'node.example.com'],
            '[::] node fqdn' => [null, '[::]', 'node.example.com', 'node.example.com'],
            'ipv6 ip' => [null, '2001:db8::5', 'node.example.com', '2001:db8::5'],
            'bracketed ipv6 alias is unbracketed' => ['[2001:db8::7]', '0.0.0.0', 'node.example.com', '2001:db8::7'],
        ];
    }

    #[DataProvider('addresses')]
    public function test_host_selection(?string $alias, string $ip, string $fqdn, string $expected): void
    {
        $this->assertSame($expected, ServerAddress::pickHost($alias, $ip, $fqdn));
    }

    public function test_uri_brackets_ipv6_only(): void
    {
        $this->assertSame('tcp://[2001:db8::5]:25565', ServerAddress::uri('tcp', '2001:db8::5', 25565));
        $this->assertSame('udp://10.0.0.5:25575', ServerAddress::uri('udp', '10.0.0.5', 25575));
        $this->assertSame('tcp://node.example.com:25565', ServerAddress::uri('tcp', 'node.example.com', 25565));
    }

    public function test_for_server_uses_the_primary_allocation_and_node(): void
    {
        $node = (new Node())->forceFill(['fqdn' => 'node.example.com']);
        $allocation = (new Allocation())->forceFill(['ip' => '0.0.0.0', 'ip_alias' => null, 'port' => 25570]);
        $server = new Server();
        $server->setRelation('node', $node);
        $server->setRelation('allocation', $allocation);

        $address = ServerAddress::forServer($server);

        $this->assertNotNull($address);
        $this->assertSame('node.example.com', $address->host);
        $this->assertSame(25570, $address->port);
    }

    public function test_for_server_without_an_allocation_is_null(): void
    {
        $server = new Server();
        $server->setRelation('node', (new Node())->forceFill(['fqdn' => 'node.example.com']));
        $server->setRelation('allocation', null);

        $this->assertNull(ServerAddress::forServer($server));
    }
}
