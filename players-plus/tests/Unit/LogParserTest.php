<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use Carbon\CarbonImmutable;
use DateTimeZone;
use DrMxrcy\PlayersPlus\Enums\LogEventType;
use DrMxrcy\PlayersPlus\Services\LogClock;
use DrMxrcy\PlayersPlus\Services\LogLine;
use DrMxrcy\PlayersPlus\Services\LogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plan 28 step 1: join/leave/UUID lines from real log formats, and nothing from anything else. */
class LogParserTest extends TestCase
{
    private const ALICE = '853c80ef-3c37-49fd-aa49-938b674adae6';

    /** @return list<string> */
    private static function fixture(string $name): array
    {
        return preg_split('/(?<=\n)/', (string) file_get_contents(dirname(__DIR__) . "/Fixtures/logs/$name.log"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Events as [Y-m-d H:i:s, type, name, uuid], dated from 2026-11-09.
     *
     * @return list<array{string, string, string, string|null}>
     */
    private static function events(string $fixture, string $firstDate = '2026-11-09'): array
    {
        $clock = new LogClock(new DateTimeZone('UTC'), null, $firstDate);

        return array_map(
            fn (array $e) => [$e[0]->format('Y-m-d H:i:s'), $e[1]->type->value, $e[1]->name, $e[1]->uuid],
            LogParser::events(self::fixture($fixture), $clock),
        );
    }

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return ['vanilla' => ['vanilla'], 'forge' => ['forge'], 'neoforge' => ['neoforge'], 'fabric' => ['fabric']];
    }

    #[DataProvider('formats')]
    public function test_join_uuid_and_leave_lines_are_parsed(string $fixture): void
    {
        $this->assertSame([
            ['2026-11-09 12:00:30', 'uuid', 'Alice', self::ALICE],
            ['2026-11-09 12:00:31', 'join', 'Alice', null],
            ['2026-11-09 12:10:44', 'leave', 'Alice', null],
        ], self::events($fixture));
    }

    public function test_paper_with_ansi_colour_codes_crlf_and_a_renamed_player(): void
    {
        $this->assertSame([
            ['2026-11-09 12:00:30', 'uuid', 'Alice', self::ALICE],
            ['2026-11-09 12:00:31', 'join', 'Alice', null],
            ['2026-11-09 12:05:00', 'join', 'Bob', null],
            ['2026-11-09 12:10:44', 'leave', 'Alice', null],
        ], self::events('paper'));
    }

    public function test_neoforge_dates_win_over_the_clock(): void
    {
        $this->assertSame('2026-11-09 12:00:31', self::events('neoforge', '2020-01-01')[1][0]);
    }

    public function test_midnight_wrap_rolls_forward_but_out_of_order_lines_do_not(): void
    {
        $this->assertSame([
            ['2026-11-09 23:58:00', 'join', 'Alice', null],
            ['2026-11-10 00:00:20', 'join', 'Bob', null],
            ['2026-11-10 00:15:00', 'leave', 'Alice', null],
            ['2026-11-10 23:50:00', 'leave', 'Bob', null],
        ], self::events('midnight'));
    }

    public function test_chat_and_other_crafted_lines_never_become_events(): void
    {
        $this->assertSame([], self::events('spoof'));
    }

    public function test_lines_without_events_and_junk_lines(): void
    {
        $this->assertSame([], self::events('noise'));
        $this->assertNull(LogParser::line(''));
        $this->assertNull(LogParser::line('[12:00:00] [Server thread/INFO]: ' . str_repeat('x', LogParser::MAX_LINE_BYTES)));
        $this->assertNull(LogParser::line('[31Foo2026 12:00:00.000] [Server thread/INFO] [x/]: Alice joined the game'));

        $line = LogParser::line("[12:00:01] [Server thread/WARN]: Can't keep up!\n");
        $this->assertInstanceOf(LogLine::class, $line);
        $this->assertSame(43201, $line->seconds);
        $this->assertNull($line->event);
    }

    public function test_the_ip_login_line_is_not_an_event(): void
    {
        $line = LogParser::line('[12:00:31] [Server thread/INFO]: Alice[/203.0.113.7:51234] logged in with entity id 117 at (12.5, 64.0, -3.5)');
        $this->assertNotNull($line);
        $this->assertNull($line->event);
    }

    public function test_geyser_style_names_follow_the_shared_name_rule(): void
    {
        $this->assertSame(LogEventType::Join, LogParser::event('.BedrockSteve joined the game')?->type);
        $this->assertNull(LogParser::event('Bad-Name joined the game'));
        $this->assertNull(LogParser::event('UUID of player Alice is not-a-uuid-at-all-not-a-uuid-at'));
    }

    public function test_first_date_counts_wraps_back_from_the_modification_time(): void
    {
        $lines = array_values(array_filter(array_map(LogParser::line(...), self::fixture('midnight'))));
        $tz = new DateTimeZone('Europe/Berlin');

        // Last line 23:50; the file was written at 23:50:05 on the 10th, one wrap before it.
        $this->assertSame('2026-11-09', LogClock::firstDate($lines, CarbonImmutable::parse('2026-11-10 23:50:05', $tz)));
        // Written just after midnight: the 23:50 line was yesterday.
        $this->assertSame('2026-11-09', LogClock::firstDate($lines, CarbonImmutable::parse('2026-11-11 00:01:00', $tz)));
        // A single-day log written at noon.
        $vanilla = array_values(array_filter(array_map(LogParser::line(...), self::fixture('vanilla'))));
        $this->assertSame('2026-11-09', LogClock::firstDate($vanilla, CarbonImmutable::parse('2026-11-09 12:10:45', $tz)));
        $this->assertNull(LogClock::firstDate([], CarbonImmutable::parse('2026-11-09 12:00:00', $tz)));
    }

    public function test_neoforge_dates_must_be_real_calendar_dates_in_range(): void
    {
        $this->assertNull(LogParser::line('[31Feb2026 12:00:00.000] [Server thread/INFO] [x/]: Alice joined the game'));
        $this->assertNull(LogParser::line('[09Nov9999 12:00:00.000] [Server thread/INFO] [x/]: Alice joined the game'));
        $this->assertNull(LogParser::line('[09Nov1970 12:00:00.000] [Server thread/INFO] [x/]: Alice joined the game'));
        $this->assertSame('2026-11-09', LogParser::line('[09Nov2026 12:00:00.000] [Server thread/INFO] [x/]: Alice joined the game')?->date);
    }

    public function test_a_forged_chain_of_wraps_never_dates_past_the_ceiling(): void
    {
        $tz = new DateTimeZone('UTC');
        $ceiling = CarbonImmutable::parse('2026-11-09 12:02:00', $tz);
        $clock = new LogClock($tz, null, '2026-11-09', $ceiling);

        // Each step back of more than five minutes reads as a midnight wrap: ten days ahead.
        $at = null;
        foreach (range(0, 10) as $i) {
            $at = $clock->at(new LogLine(43000 - $i * 600));
        }

        $this->assertNotNull($at);
        $this->assertTrue($at->lessThanOrEqualTo($ceiling));
        $this->assertSame('2026-11-09 10:16:40', $at->format('Y-m-d H:i:s'), 'the latest such time not after the ceiling');
        $this->assertTrue($clock->last()?->lessThanOrEqualTo($ceiling));
    }

    public function test_a_future_explicit_date_is_ignored_and_the_clock_stays(): void
    {
        $tz = new DateTimeZone('UTC');
        $clock = new LogClock($tz, CarbonImmutable::parse('2026-11-09 11:00:00', $tz), null, CarbonImmutable::parse('2026-11-09 12:02:00', $tz));

        $this->assertNull($clock->at(new LogLine(3600, '2027-01-01')));
        $this->assertSame('2026-11-09 11:00:00', $clock->last()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 11:30:00', $clock->at(new LogLine(41400))?->format('Y-m-d H:i:s'));
    }

    public function test_a_stored_clock_after_the_ceiling_is_pulled_back(): void
    {
        $tz = new DateTimeZone('UTC');
        $clock = new LogClock($tz, CarbonImmutable::parse('2026-11-20 08:00:00', $tz), null, CarbonImmutable::parse('2026-11-09 12:02:00', $tz));

        $this->assertSame('2026-11-09 12:00:00', $clock->at(new LogLine(43200))?->format('Y-m-d H:i:s'));
    }

    public function test_clock_continues_from_the_previous_scan(): void
    {
        $tz = new DateTimeZone('UTC');
        $clock = new LogClock($tz, CarbonImmutable::parse('2026-11-09 23:59:00', $tz));

        $this->assertSame('2026-11-10 00:00:10', $clock->at(new LogLine(10))?->format('Y-m-d H:i:s'));
        $this->assertNull((new LogClock($tz))->at(new LogLine(10)));
    }
}
