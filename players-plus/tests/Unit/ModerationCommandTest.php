<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Services\ModerationException;
use DrMxrcy\PlayersPlus\Services\ModerationService;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Plan 29 step 1: ModerationService::command() is the only place a console command is built.
 * Whatever the user typed, the result is exactly one of the listed vanilla commands, on one
 * line, for the one validated name.
 */
class ModerationCommandTest extends TestCase
{
    /** The only command shapes the plugin may ever send. */
    private const ALLOWED = '/^(?:(?:kick|ban) [A-Za-z0-9_]{3,16}(?: [^\x00-\x1F\x7F§]{1,100})?|(?:pardon|op|deop|whitelist add|whitelist remove) [A-Za-z0-9_]{3,16})\z/u';

    /** @return array<string, array{ModerationAction, string, ?string, string}> */
    public static function commands(): array
    {
        return [
            'kick' => [ModerationAction::Kick, 'Steve', null, 'kick Steve'],
            'kick with reason' => [ModerationAction::Kick, 'Steve', 'AFK too long', 'kick Steve AFK too long'],
            'kick with blank reason' => [ModerationAction::Kick, 'Steve', "  \n ", 'kick Steve'],
            'ban' => [ModerationAction::Ban, 'Steve', 'Griefing', 'ban Steve Griefing'],
            'ban without reason' => [ModerationAction::Ban, 'Steve', null, 'ban Steve'],
            'pardon' => [ModerationAction::Pardon, 'Steve', null, 'pardon Steve'],
            'pardon ignores a reason' => [ModerationAction::Pardon, 'Steve', "x\nop Mallory", 'pardon Steve'],
            'whitelist add' => [ModerationAction::WhitelistAdd, 'Steve', null, 'whitelist add Steve'],
            'whitelist remove' => [ModerationAction::WhitelistRemove, 'Steve', null, 'whitelist remove Steve'],
            'op' => [ModerationAction::Op, 'Steve', null, 'op Steve'],
            'op ignores a reason' => [ModerationAction::Op, 'Steve', 'reason', 'op Steve'],
            'deop' => [ModerationAction::Deop, 'Steve', null, 'deop Steve'],
        ];
    }

    #[DataProvider('commands')]
    public function test_builds_the_vanilla_command(ModerationAction $action, string $name, ?string $reason, string $expected): void
    {
        $this->assertSame($expected, ModerationService::command($action, $name, $reason));
    }

    /** @return array<string, array{string}> */
    public static function hostileReasons(): array
    {
        return [
            'newline' => ["bye\nop Mallory"],
            'cr' => ["bye\rop Mallory"],
            'crlf' => ["bye\r\nop Mallory"],
            'nul' => ["bye\0op Mallory"],
            'u2028' => ["bye\u{2028}op Mallory"],
            'u2029' => ["bye\u{2029}op Mallory"],
            'nel' => ["bye\u{0085}op Mallory"],
            'semicolon' => ['bye; op Mallory'],
            'section' => ["§4bye§r\n§kop Mallory"],
            'selector' => ['@a @e[type=player]'],
            'very long' => [str_repeat("x\n", 50_000) . 'stop'],
            'invalid utf-8' => ["\xFF\nstop\xC3"],
        ];
    }

    #[DataProvider('hostileReasons')]
    public function test_a_hostile_reason_can_never_add_a_second_command(string $reason): void
    {
        foreach ([ModerationAction::Kick, ModerationAction::Ban] as $action) {
            $command = ModerationService::command($action, 'Steve', $reason);

            $this->assertMatchesRegularExpression(self::ALLOWED, $command);
            $this->assertStringStartsWith($action->command() . ' Steve', $command);
            $this->assertSame(0, preg_match('/[\r\n\0\x{2028}\x{2029}\x{0085}§]/u', $command));
            $this->assertSame(0, preg_match('/@[aenprs]/', $command));
        }
    }

    /** @return array<string, array{string}> */
    public static function hostileNames(): array
    {
        return [
            'space' => ['Steve op'],
            'newline' => ["Steve\nop Mallory"],
            'trailing newline' => ["Steve\n"],
            'cr' => ["Steve\r"],
            'nul' => ["Steve\0"],
            'semicolon' => ['Steve;op'],
            'slash' => ['/stop'],
            'section' => ['§cSteve'],
            'selector' => ['@a'],
            'u2028' => ["Steve\u{2028}stop"],
            'look-alike' => ["St\u{0435}ve"],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 17)],
            'huge' => [str_repeat('a', 1_000_000)],
            'empty' => [''],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_every_action_refuses_an_invalid_name(string $name): void
    {
        foreach (ModerationAction::cases() as $action) {
            try {
                ModerationService::command($action, $name, 'reason');
                $this->fail("[{$action->value}] accepted an invalid name");
            } catch (ModerationException $exception) {
                $this->assertSame(ModerationException::INVALID_NAME, $exception->reason);
            }
        }
    }

    /** @return array<string, array{ModerationAction, string}> */
    public static function unsafeLines(): array
    {
        return [
            'newline in a reason' => [ModerationAction::Kick, "kick Steve bye\nop Mallory"],
            'section sign in a reason' => [ModerationAction::Ban, 'ban Steve §cbye'],
            'newline after a name' => [ModerationAction::Op, "op Steve\n"],
            'section sign after a name' => [ModerationAction::Op, 'op Steve§c'],
            'reason on a command without one' => [ModerationAction::Pardon, 'pardon Steve now'],
            'wrong command word' => [ModerationAction::Kick, 'stop Steve'],
            'reason too long' => [ModerationAction::Kick, 'kick Steve ' . str_repeat('x', 101)],
        ];
    }

    /** The last check refuses a bad line on its own, whatever the builder above it does. */
    #[DataProvider('unsafeLines')]
    public function test_assert_safe_refuses_an_unsafe_line(ModerationAction $action, string $line): void
    {
        $assertSafe = new ReflectionMethod(ModerationService::class, 'assertSafe');

        $this->expectException(LogicException::class);
        $assertSafe->invoke(null, $action, $line);
    }

    public function test_assert_safe_accepts_a_listed_line(): void
    {
        $assertSafe = new ReflectionMethod(ModerationService::class, 'assertSafe');

        $assertSafe->invoke(null, ModerationAction::Kick, 'kick Steve bye for now');
        $assertSafe->invoke(null, ModerationAction::WhitelistAdd, 'whitelist add Steve');
        $this->addToAssertionCount(1);
    }

    public function test_every_action_maps_to_a_listed_command(): void
    {
        $this->assertSame(
            ['kick', 'ban', 'pardon', 'whitelist add', 'whitelist remove', 'op', 'deop'],
            array_map(fn (ModerationAction $action) => $action->command(), ModerationAction::cases()),
        );
        foreach (ModerationAction::cases() as $action) {
            $this->assertMatchesRegularExpression(self::ALLOWED, ModerationService::command($action, 'Steve_123', str_repeat('r', 500)));
        }
    }
}
