<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Support\ConsoleText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 29 step 1: a kick or ban reason is free text typed by a panel user and becomes the
 * tail of a console line, so it must come out as one line of printable text.
 */
class ConsoleTextTest extends TestCase
{
    /** @return array<string, array{mixed, string}> input => expected */
    public static function reasons(): array
    {
        return [
            'plain' => ['Griefing spawn', 'Griefing spawn'],
            'null' => [null, ''],
            'empty' => ['', ''],
            'not a string' => [['x'], ''],
            'only whitespace' => ["  \t \n ", ''],
            'trimmed' => ['  spam  ', 'spam'],
            'collapsed spaces' => ['too    many   spaces', 'too many spaces'],
            'newline' => ["bye\nop Mallory", 'bye op Mallory'],
            'carriage return' => ["bye\rop Mallory", 'bye op Mallory'],
            'crlf' => ["bye\r\nop Mallory", 'bye op Mallory'],
            'many newlines' => ["a\n\n\n\nb", 'a b'],
            'line separator' => ["bye\u{2028}op Mallory", 'bye op Mallory'],
            'paragraph separator' => ["bye\u{2029}op Mallory", 'bye op Mallory'],
            'next line (NEL)' => ["bye\u{0085}op Mallory", 'bye op Mallory'],
            'vertical tab and form feed' => ["a\x0Bb\x0Cc", 'a b c'],
            'nul' => ["bye\0op Mallory", 'bye op Mallory'],
            'escape sequence' => ["\e[31mred\e[0m", '[31mred [0m'],
            'delete char' => ["a\x7Fb", 'a b'],
            'c1 control' => ["a\u{009B}b", 'a b'],
            'section colour codes' => ['§cRed §lbold§r text', 'Red bold text'],
            'lone section sign at end' => ['oops§', 'oops'],
            'section sign before newline' => ["a§\nop Mallory", 'a op Mallory'],
            'zero width and bidi' => ["a\u{200B}b\u{202E}c\u{FEFF}d", 'abcd'],
            'nbsp becomes space' => ["a\u{00A0}b", 'a b'],
            'semicolon kept as text' => ['spam; op Mallory', 'spam; op Mallory'],
            'slash kept as text' => ['/op Mallory', '/op Mallory'],
            'selector neutralised' => ['bye @a and @e[type=pig]', 'bye @ a and @ e[type=pig]'],
            'email not a selector' => ['mail me@example.com', 'mail me@ example.com'],
            'plain at sign kept' => ['meet @ 5', 'meet @ 5'],
            'umlauts kept' => ['Grüße, tschüß', 'Grüße, tschüß'],
            'emoji kept' => ['bye 👋', 'bye 👋'],
            'invalid utf-8 scrubbed' => ["bad\xC3(byte", 'bad?(byte'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_reason_is_one_clean_line(mixed $input, string $expected): void
    {
        $this->assertSame($expected, ConsoleText::reason($input));
    }

    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'newline' => ["x\nstop"],
            'cr' => ["x\rstop"],
            'nul' => ["x\0stop"],
            'u2028' => ["x\u{2028}stop"],
            'u2029' => ["x\u{2029}stop"],
            'section' => ["§k§l§m\n§r"],
            'mixed garbage' => ["\r\n\0\u{2028}\u{2029}\u{0085}\x1B§a;/op @a\t\x7F"],
            'very long with a newline late' => [str_repeat('a', 5000) . "\nstop"],
            'invalid utf-8 around a newline' => ["\xFF\n\xFE"],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_never_leaves_a_line_break_control_char_or_section_sign(string $input): void
    {
        $reason = ConsoleText::reason($input);

        $this->assertTrue(mb_check_encoding($reason, 'UTF-8'));
        $this->assertSame(0, preg_match('/[\p{C}\p{Zl}\p{Zp}§]/u', $reason));
        $this->assertStringNotContainsString("\n", $reason);
        $this->assertStringNotContainsString("\r", $reason);
        $this->assertLessThanOrEqual(ConsoleText::MAX_REASON, mb_strlen($reason));
    }

    public function test_reason_is_capped_at_100_characters(): void
    {
        $this->assertSame(100, ConsoleText::MAX_REASON);
        $this->assertSame(str_repeat('a', 100), ConsoleText::reason(str_repeat('a', 250)));
        // Characters, not bytes: a multi-byte reason is not cut inside a character.
        $this->assertSame(str_repeat('ü', 100), ConsoleText::reason(str_repeat('ü', 250)));
        // Cut after cleaning, and no trailing space is left behind by the cut.
        $this->assertSame(str_repeat('a', 99), ConsoleText::reason(str_repeat('a', 99) . ' bcdef'));
        $this->assertSame(str_repeat('a', 100), ConsoleText::reason(str_repeat("\n", 100) . str_repeat('a', 150)));
    }

    public function test_megabytes_of_input_are_handled_quickly(): void
    {
        $start = microtime(true);
        $reason = ConsoleText::reason(str_repeat("§a\n\u{2028}x", 500_000));

        $this->assertLessThanOrEqual(ConsoleText::MAX_REASON, mb_strlen($reason));
        $this->assertLessThan(2.0, microtime(true) - $start);
    }
}
