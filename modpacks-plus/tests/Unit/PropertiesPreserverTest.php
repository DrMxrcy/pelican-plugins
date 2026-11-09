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

    public function test_a_pack_never_sets_level_name_even_when_the_server_has_none(): void
    {
        $merged = PropertiesPreserver::merge("motd=hi\n", "level-name=mods\nmotd=pack\npvp=true\n", [], 25565);

        $this->assertArrayNotHasKey('level-name', PropertiesPreserver::values($merged));
        $this->assertSame("motd=pack\npvp=true\nserver-port=25565\nquery.port=25565\n", $merged);

        // Nor with an empty preserved list and no server file at all.
        $this->assertArrayNotHasKey('level-name', PropertiesPreserver::values(PropertiesPreserver::merge('', "level-name=../../etc\n", [], null)));
    }

    public function test_a_pack_never_sets_the_ports(): void
    {
        // With an allocation port: the allocation wins over the pack.
        $values = PropertiesPreserver::values(PropertiesPreserver::merge("motd=x\n", "server-port=1\nquery.port=2\n", [], 25570));
        $this->assertSame(['25570', '25570'], [$values['server-port'], $values['query.port']]);

        // Without one: the server's values, and the pack's never written when the server has none.
        $values = PropertiesPreserver::values(PropertiesPreserver::merge("server-port=25565\n", "server-port=1\nquery.port=2\n", [], null));
        $this->assertSame('25565', $values['server-port']);
        $this->assertArrayNotHasKey('query.port', $values);
        $this->assertSame("motd=x\n", PropertiesPreserver::merge("motd=x\n", "server-port=1\nquery.port=2\n", [], 0));
    }

    /** @return array<string, array{string}> */
    public static function hostileLevelNames(): array
    {
        return [
            'traversal' => ['../outside'],
            'absolute' => ['/etc'],
            'backslash' => ['a\\b'],
            'mods' => ['mods'],
            'config subfolder' => ['config/world'],
            'own folder' => ['.modpacks-plus/x'],
            'case' => ['MODS'],
            'empty' => [''],
        ];
    }

    #[DataProvider('hostileLevelNames')]
    public function test_a_hostile_level_name_on_the_server_fails_closed(string $levelName): void
    {
        $this->expectException(UnsafePlanException::class);
        $this->expectExceptionMessageMatches('/unsafe_level_name/');

        PropertiesPreserver::merge("level-name=$levelName\n", "motd=pack\n", PropertiesPreserver::DEFAULT_KEYS, 25565);
    }

    public function test_safe_level_names_pass(): void
    {
        foreach (['world', 'survival', 'saves/world', 'my world'] as $name) {
            PropertiesPreserver::assertLevelName($name);
        }
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string}> key => [pack value, server value] */
    public static function serverOnlyKeys(): array
    {
        return [
            'rcon on' => ['enable-rcon', 'true'],
            'rcon password' => ['rcon.password', 'hunter2'],
            'rcon port' => ['rcon.port', '25575'],
            'query on' => ['enable-query', 'true'],
            'query port' => ['query.port', '1'],
            'server port' => ['server-port', '1'],
            'bind address' => ['server-ip', '10.0.0.5'],
            'management server on' => ['management-server-enabled', 'true'],
            'management server host' => ['management-server-host', '0.0.0.0'],
            'management server port' => ['management-server-port', '25585'],
            'management server secret' => ['management-server-secret', 'x'],
            'jmx' => ['enable-jmx-monitoring', 'true'],
            'offline mode' => ['online-mode', 'false'],
            'whitelist off' => ['white-list', 'false'],
            'whitelist not enforced' => ['enforce-whitelist', 'false'],
            'op level' => ['op-permission-level', '4'],
            'function level' => ['function-permission-level', '4'],
            'resource pack url' => ['resource-pack', 'https://evil.example/grab.zip'],
            'resource pack hash' => ['resource-pack-sha1', 'abc'],
            'resource pack prompt' => ['resource-pack-prompt', 'click'],
            'resource pack id' => ['resource-pack-id', 'x'],
            'require resource pack' => ['require-resource-pack', 'true'],
            'level name' => ['level-name', 'mods'],
            'mixed case' => ['Enable-RCON', 'true'],
            'text filtering' => ['text-filtering-config', 'https://evil.example/f.json'],
            'bug report link' => ['bug-report-link', 'https://evil.example'],
            'transfers' => ['accepts-transfers', 'true'],
            'command blocks' => ['enable-command-block', 'true'],
            'ip logging' => ['log-ips', 'true'],
            'status' => ['enable-status', 'false'],
            'hidden players' => ['hide-online-players', 'true'],
            'chat signing' => ['enforce-secure-profile', 'false'],
            'unknown future key' => ['some-key-mojang-adds-in-2030', 'x'],
            'gameplay key in odd case' => ['MOTD', 'x'],
        ];
    }

    #[DataProvider('serverOnlyKeys')]
    public function test_a_server_only_key_never_comes_from_the_pack(string $key, string $value): void
    {
        $this->assertTrue(PropertiesPreserver::isServerOnly($key));

        // The server lacks it, no allocation port, an empty admin list: the pack's line is dropped.
        $merged = PropertiesPreserver::merge("motd=hi\n", "$key=$value\npvp=true\n", [], null);
        $this->assertSame("motd=hi\npvp=true\n", $merged, $key);

        // The server has it: the server's value stays.
        $merged = PropertiesPreserver::merge("$key=server-value\n", "$key=$value\n", [], null);
        $this->assertSame("$key=server-value\n", $merged, $key);
    }

    public function test_every_allowlisted_gameplay_key_from_the_pack_is_applied(): void
    {
        foreach (PropertiesPreserver::PACK_KEYS as $key) {
            $this->assertFalse(PropertiesPreserver::isServerOnly($key), $key);
            $merged = PropertiesPreserver::mergeReport("$key=server\n", "$key=pack\n", [], null);
            $this->assertSame("$key=pack\n", $merged['contents'], $key);
            $this->assertSame([], $merged['dropped'], $key);
        }
    }

    public function test_the_preserved_list_only_narrows_the_allowlist(): void
    {
        // A gameplay key on the list keeps the server's value.
        $this->assertSame("motd=server\n", PropertiesPreserver::merge("motd=server\n", "motd=pack\n", ['motd'], null));
        // Listing nothing never lets a server-only key through.
        $this->assertSame("white-list=true\n", PropertiesPreserver::merge("white-list=true\n", "white-list=false\n", [], null));
    }

    public function test_dropped_pack_keys_are_reported_by_name(): void
    {
        $report = PropertiesPreserver::mergeReport(
            "level-name=world\nmotd=hi\n",
            "level-name=mods\nenable-rcon=true\nrcon.password=x\nmotd=pack\nserver-port=1\nenable-rcon=true\nfuture-key=1\n",
            [],
            25565,
        );

        $this->assertSame(['level-name', 'enable-rcon', 'rcon.password', 'server-port', 'future-key'], $report['dropped']);
        $this->assertSame("level-name=world\nmotd=pack\nserver-port=25565\nquery.port=25565\n", $report['contents']);
    }

    public function test_the_admin_list_is_sanitised(): void
    {
        $this->assertSame(['motd', 'pvp', 'query.port'], PropertiesPreserver::keys("motd, pvp\nquery.port ,motd"));
        $this->assertSame(['motd'], PropertiesPreserver::keys(['motd', 'bad key', "evil\nkey", 3, '']));
        $this->assertSame(PropertiesPreserver::DEFAULT_KEYS, PropertiesPreserver::keys(null));
        // An empty setting means the defaults.
        $this->assertSame(PropertiesPreserver::DEFAULT_KEYS, PropertiesPreserver::keys([]));
        $this->assertSame(PropertiesPreserver::DEFAULT_KEYS, PropertiesPreserver::keys(''));
        $this->assertSame(PropertiesPreserver::DEFAULT_KEYS, PropertiesPreserver::keys(['bad key', '']));
        $this->assertCount(PropertiesPreserver::MAX_KEYS, PropertiesPreserver::keys(array_map(fn ($i) => "k$i", range(1, 500))));
    }
}
