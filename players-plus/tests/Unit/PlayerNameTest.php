<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Support\PlayerName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 29 step 1: a target name is the only user text that reaches a console command besides
 * the reason, so it must be a plain Java Edition name and nothing else.
 */
class PlayerNameTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validNames(): array
    {
        return [
            'three chars' => ['abc'],
            'sixteen chars' => ['abcdefghijklmnop'],
            'mixed' => ['Steve_123'],
            'underscores' => ['___'],
            'digits' => ['123'],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ab'],
            'one char' => ['a'],
            'too long' => ['abcdefghijklmnopq'],
            'very long' => [str_repeat('a', 100_000)],
            'space' => ['Steve Jobs'],
            'leading space' => [' Steve'],
            'trailing space' => ['Steve '],
            'trailing newline ($ would match)' => ["Steve\n"],
            'newline then command' => ["Steve\nop Mallory"],
            'carriage return' => ["Steve\rop Mallory"],
            'crlf' => ["Steve\r\n"],
            'nul' => ["Steve\0"],
            'nul in middle' => ["Ste\0ve"],
            'tab' => ["Steve\t"],
            'semicolon' => ['Steve;op'],
            'slash' => ['/op'],
            'slash in name' => ['Steve/op'],
            'section sign' => ['Steve§c'],
            'at selector' => ['@a'],
            'at selector with args' => ['@e[type=player]'],
            'dot (floodgate prefix)' => ['.BedrockGuy'],
            'star (floodgate prefix)' => ['*BedrockGuy'],
            'hyphen' => ['Steve-1'],
            'quote' => ['Steve"'],
            'unicode line separator' => ["Steve\u{2028}op"],
            'unicode paragraph separator' => ["Steve\u{2029}op"],
            'next line (NEL)' => ["Steve\u{0085}"],
            'cyrillic look-alike' => ["St\u{0435}ve"],
            'fullwidth look-alike' => ["\u{FF33}teve"],
            'zero width space' => ["Ste\u{200B}ve"],
            'combining mark' => ["Steve\u{0301}"],
            'invalid utf-8' => ["Steve\xC3"],
            'null' => [null],
            'int' => [123456],
            'array' => [['Steve']],
        ];
    }

    #[DataProvider('validNames')]
    public function test_accepts_plain_java_names(string $name): void
    {
        $this->assertTrue(PlayerName::isValid($name));
        $this->assertSame($name, PlayerName::require($name));
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_anything_else(mixed $name): void
    {
        $this->assertFalse(PlayerName::isValid($name));

        $this->expectException(InvalidArgumentException::class);
        PlayerName::require($name);
    }
}
