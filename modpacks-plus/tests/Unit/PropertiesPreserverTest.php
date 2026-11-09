<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Services\PropertiesPreserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plan 36 step 2: merging a pack's server.properties into the server's own. */
class PropertiesPreserverTest extends TestCase
{
    private const CURRENT = "#Minecraft server properties\n#Mon Nov 09 2026\nmotd=My server\nlevel-name=survival\nserver-port=25565\nallow-flight=false\ncustom-plugin-key=keep me\n\n# admin note\ndifficulty=hard\nmax-players=20\n";

    private const PACK = "# from the pack\nallow-flight=true\nmotd=Pack MOTD\ndifficulty=peaceful\nlevel-name=../../etc\nlevel-type=skyblock\nserver-port=1\nmax-tick-time=-1\n";

    public function test_keeps_comments_order_and_unknown_keys_and_applies_the_preserved_list(): void
    {
        $merged = PropertiesPreserver::merge(self::CURRENT, self::PACK, PropertiesPreserver::DEFAULT_KEYS, 25570);

        $this->assertSame(
            "#Minecraft server properties\n#Mon Nov 09 2026\n"
            ."motd=My server\n"         // preserved
            ."level-name=survival\n"    // always preserved
            ."server-port=25570\n"      // allocation
            ."allow-flight=true\n"      // the pack's value
            ."custom-plugin-key=keep me\n\n# admin note\n"
            ."difficulty=hard\n"        // preserved
            ."max-players=20\n"
            ."level-type=skyblock\nmax-tick-time=-1\n" // only the pack has them: appended
            ."query.port=25570\n",      // allocation, appended
            $merged,
        );
    }

    public function test_a_key_off_the_preserved_list_takes_the_packs_value(): void
    {
        $values = PropertiesPreserver::values(PropertiesPreserver::merge(self::CURRENT, self::PACK, ['max-players'], 25565));

        $this->assertSame('Pack MOTD', $values['motd']);
        $this->assertSame('peaceful', $values['difficulty']);
        $this->assertSame('20', $values['max-players']);
        // level-name can never come from a pack, whatever the list says.
        $this->assertSame('survival', $values['level-name']);
    }

    public function test_ports_always_follow_the_allocation(): void
    {
        $values = PropertiesPreserver::values(PropertiesPreserver::merge("server-port=1\nquery.port=2\n", "server-port=3\nquery.port=4\n", [], 25599));

        $this->assertSame('25599', $values['server-port']);
        $this->assertSame('25599', $values['query.port']);
    }

    public function test_without_an_allocation_the_servers_ports_are_kept(): void
    {
        $values = PropertiesPreserver::values(PropertiesPreserver::merge("server-port=1\nquery.port=2\n", "server-port=3\nquery.port=4\n", [], null));

        $this->assertSame('1', $values['server-port']);
        $this->assertSame('2', $values['query.port']);
    }

    public function test_untouched_lines_stay_byte_for_byte_and_crlf_is_kept(): void
    {
        $current = "motd : spaced\r\npvp=true\r\n";
        $merged = PropertiesPreserver::merge($current, "pvp=false\n", ['motd'], null);

        $this->assertSame("motd : spaced\r\npvp=false\r\n", $merged);
    }

    public function test_without_a_server_file_the_pack_file_gets_the_allocation_ports(): void
    {
        $merged = PropertiesPreserver::merge('', "motd=Pack\nserver-port=1\n", PropertiesPreserver::DEFAULT_KEYS, 25565);

        $this->assertSame("motd=Pack\nserver-port=25565\nquery.port=25565\n", $merged);
    }

    public function test_the_last_occurrence_of_a_duplicate_key_wins_and_every_occurrence_is_set(): void
    {
        $merged = PropertiesPreserver::merge("motd=a\nmotd=b\n", "motd=pack\n", ['motd'], null);

        $this->assertSame("motd=b\nmotd=b\n", $merged);
        $this->assertSame('b', PropertiesPreserver::values($merged)['motd']);
    }

    /** @return array<string, array{string, string}> */
    public static function hostile(): array
    {
        return [
            'continuation in the pack' => ["motd=a\n", "level-type=x\\\nlevel-name=evil\n"],
            'escaped key in the pack' => ["motd=a\n", "level\\-name=evil\n"],
            'continuation on the server' => ["motd=a\\\n", "motd=b\n"],
            'escaped key on the server' => ["mo\\tdx=a\n", "motd=b\n"],
            'oversized pack file' => ["motd=a\n", str_repeat('x', PropertiesPreserver::MAX_BYTES + 1)],
        ];
    }

    #[DataProvider('hostile')]
    public function test_escapes_and_continuations_fail_closed(string $current, string $incoming): void
    {
        $this->expectException(UnsafePlanException::class);
        $this->expectExceptionMessageMatches('/properties_unmergeable/');

        PropertiesPreserver::merge($current, $incoming, PropertiesPreserver::DEFAULT_KEYS, 25565);
    }

    public function test_an_even_number_of_trailing_backslashes_is_a_literal_value(): void
    {
        $this->assertSame(['path' => 'C:\\\\'], PropertiesPreserver::values("path=C:\\\\\n"));
    }

    public function test_the_admin_list_is_sanitised(): void
    {
        $this->assertSame(['motd', 'pvp', 'query.port'], PropertiesPreserver::keys("motd, pvp\nquery.port ,motd"));
        $this->assertSame(['motd'], PropertiesPreserver::keys(['motd', 'bad key', "evil\nkey", 3, '']));
        $this->assertSame(PropertiesPreserver::DEFAULT_KEYS, PropertiesPreserver::keys(null));
        $this->assertSame([], PropertiesPreserver::keys([]));
        $this->assertCount(PropertiesPreserver::MAX_KEYS, PropertiesPreserver::keys(array_map(fn ($i) => "k$i", range(1, 500))));
    }
}
