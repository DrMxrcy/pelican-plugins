<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\Services\PlayerStats;
use PHPUnit\Framework\TestCase;

class PlayerStatsTest extends TestCase
{
    public function test_modern_stats_file(): void
    {
        $stats = PlayerStats::parse((string) file_get_contents(dirname(__DIR__) . '/Fixtures/stats/853c80ef-3c37-49fd-aa49-938b674adae6.json'));

        $this->assertSame([
            'play_time' => 10800,
            'deaths' => 7,
            'mob_kills' => 152,
            'player_kills' => 3,
            'distance_walked' => 4523100,
            'jumps' => 2048,
        ], $stats);
    }

    public function test_1_13_to_1_16_play_one_minute_and_pre_1_13_flat_format(): void
    {
        $this->assertSame(60, PlayerStats::parse('{"stats":{"minecraft:custom":{"minecraft:play_one_minute":1200}}}')['play_time'] ?? null);

        $legacy = PlayerStats::parse('{"stat.playOneMinute":72000,"stat.deaths":2,"stat.mobKills":5,"stat.playerKills":1,"stat.walkOneCm":1000,"stat.jump":9,"achievement.openInventory":1}');
        $this->assertSame(['play_time' => 3600, 'deaths' => 2, 'mob_kills' => 5, 'player_kills' => 1, 'distance_walked' => 1000, 'jumps' => 9], $legacy);
    }

    public function test_missing_keys_and_junk_values_are_null_not_errors(): void
    {
        $stats = PlayerStats::parse('{"stats":{"minecraft:custom":{"minecraft:deaths":-5,"minecraft:jump":"9","minecraft:mob_kills":1e400,"minecraft:player_kills":99999999999999999999999,"minecraft:walk_one_cm":250}}}');

        $this->assertSame([
            'play_time' => null,
            'deaths' => null,
            'mob_kills' => null,
            'player_kills' => null,
            'distance_walked' => 250,
            'jumps' => null,
        ], $stats);
    }

    public function test_unparseable_files_have_no_stats(): void
    {
        // A file without a single known stat is "no stats yet", not six dashes.
        foreach (['', 'nope', '[]', '"x"', '{"stats":"x"}', '{"stats":{"minecraft:custom":[1,2]}}', '{"stats":{}}', '{"stats":{"minecraft:custom":{"mod:x":1}}}', '{"DataVersion":1}', '{"stats":{"minecraft:custom":{"minecraft:deaths":-1}}}'] as $content) {
            $this->assertNull(PlayerStats::parse($content), $content);
        }
    }

    public function test_level_name_is_a_safe_relative_path_or_world(): void
    {
        $this->assertSame('world', PlayerStats::levelName(null));
        $this->assertSame('world', PlayerStats::levelName(''));
        $this->assertSame('survival', PlayerStats::levelName('survival'));
        $this->assertSame('My World', PlayerStats::levelName('My World'));
        $this->assertSame('worlds/main', PlayerStats::levelName('worlds/main'));
        foreach (['../../etc', '/etc', 'a/../b', 'a\\b', "a\0b", 'a//b', str_repeat('x', 200), "a\nb", './world'] as $hostile) {
            $this->assertSame('world', PlayerStats::levelName($hostile), $hostile);
        }
    }
}
