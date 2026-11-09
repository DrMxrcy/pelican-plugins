<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\ServerProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** level-name is read exactly, or the install refuses (it decides which folder is the world). */
class ServerPropertiesTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function files(): array
    {
        return [
            'plain' => ["motd=hi\nlevel-name=survival\n", 'survival'],
            'colon and spaces' => ["level-name : my world\n", 'my world'],
            'whitespace separator' => ["level-name survival\n", 'survival'],
            'last one wins' => ["level-name=a\nlevel-name=b\n", 'b'],
            'absent' => ["motd=hi\n", null],
            'empty value' => ["level-name=\n", ''],
            'comments ignored' => ["#level-name=x\n! level-name=y\nlevel-name=z\n", 'z'],
            'similar key' => ["level-names=x\n", null],
            'escape in another value is fine' => ["motd=\\u00a7cHi\nlevel-name=world\n", 'world'],
            'value escape handed on' => ["level-name=a\\b\n", 'a\\b'],
            'even trailing backslashes are not a continuation' => ["motd=a\\\\\nlevel-name=w\n", 'w'],
        ];
    }

    #[DataProvider('files')]
    public function test_reads_level_name(string $contents, ?string $expected): void
    {
        $this->assertSame($expected, ServerProperties::levelName($contents));
    }

    /** @return array<string, array{string}> */
    public static function ambiguous(): array
    {
        return [
            'escaped key' => ["level\\-name=elsewhere\n"],
            'escaped separator in key' => ["level\\=name=x\n"],
            'continuation hiding the key' => ["motd=hi \\\nlevel-name=fake\nlevel-name=world\n"],
            'continued level-name' => ["level-name=wor\\\n  ld\n"],
        ];
    }

    #[DataProvider('ambiguous')]
    public function test_escapes_and_continuations_fail_closed(string $contents): void
    {
        try {
            ServerProperties::levelName($contents);
            $this->fail('Ambiguous server.properties was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('unsafe_level_name', $exception->reason);
        }
    }
}
