<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Services\QueryPortResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryPortResolverTest extends TestCase
{
    /** @return array<string, array{string, int|null}> */
    public static function properties(): array
    {
        return [
            'query off' => ["enable-query=false\nquery.port=25575\n", null],
            'query key missing' => ["query.port=25575\n", null],
            'allowed allocation port' => ["enable-query=true\nquery.port=25575\n", 25575],
            'game port' => ["enable-query=TRUE\nquery.port=25565\n", 25565],
            'no query.port: game port' => ["enable-query=true\n", 25565],
            'empty query.port: game port' => ["enable-query=true\nquery.port=\n", 25565],
            'foreign port rejected' => ["enable-query=true\nquery.port=22\n", null],
            'foreign high port rejected' => ["enable-query=true\nquery.port=6379\n", null],
            'garbage rejected' => ["enable-query=true\nquery.port=abc\n", null],
            'negative rejected' => ["enable-query=true\nquery.port=-25575\n", null],
            'overflow rejected' => ["enable-query=true\nquery.port=000000025575\n", null],
            'host:port rejected' => ["enable-query=true\nquery.port=10.0.0.1:25575\n", null],
        ];
    }

    #[DataProvider('properties')]
    public function test_query_port_from_properties(string $content, ?int $expected): void
    {
        $this->assertSame($expected, QueryPortResolver::fromProperties($content, 25565, [25565, 25575]));
    }
}
