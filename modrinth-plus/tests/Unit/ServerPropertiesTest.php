<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\ServerProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plan 12: the datapack folder follows server.properties' `level-name`. */
class ServerPropertiesTest extends TestCase
{
    public function test_reads_level_name_from_a_minecraft_written_file(): void
    {
        $content = "#Minecraft server properties\n#Sat Nov 08 12:00:00 UTC 2026\nallow-flight=false\nlevel-name=survival\nlevel-seed=\nmotd=A Minecraft Server\n";

        $this->assertSame('survival', ServerProperties::levelName($content));
    }

    public function test_missing_file_defaults_to_world(): void
    {
        $this->assertSame('world', ServerProperties::levelName(null));
    }

    public function test_file_without_level_name_defaults_to_world(): void
    {
        $this->assertSame('world', ServerProperties::levelName("motd=hi\nmax-players=20\n"));
    }

    public function test_empty_level_name_defaults_to_world(): void
    {
        $this->assertSame('world', ServerProperties::levelName("level-name=\n"));
        $this->assertSame('world', ServerProperties::levelName("level-name=   \n"));
    }

    public function test_commented_out_level_name_is_ignored(): void
    {
        $this->assertSame('world', ServerProperties::levelName("#level-name=old\n! level-name=other\n"));
    }

    public function test_last_occurrence_wins(): void
    {
        $this->assertSame('second', ServerProperties::levelName("level-name=first\nlevel-name=second\n"));
    }

    public function test_crlf_line_endings_and_spaces_around_separator(): void
    {
        $this->assertSame('my world', ServerProperties::levelName("motd=x\r\n  level-name = my world\r\npvp=true\r\n"));
    }

    public function test_colon_separator_and_java_escapes(): void
    {
        $this->assertSame('my:world=1', ServerProperties::levelName('level-name:my\\:world\\=1'));
        $this->assertSame("welt\u{e4}", ServerProperties::levelName('level-name=welt\\u00e4'));
    }

    public function test_a_similar_key_is_not_matched(): void
    {
        $this->assertSame('world', ServerProperties::levelName("level-name-old=nope\nlevel-type=minecraft\\:normal\n"));
    }

    public function test_nested_folder_is_kept_without_surrounding_slashes(): void
    {
        $this->assertSame('worlds/main', ServerProperties::levelName("level-name=/worlds/main/\n"));
    }

    /** @return array<string, array{string}> */
    public static function unsafeLevelNames(): array
    {
        return [
            'parent folder' => ['../outside'],
            'parent in the middle' => ['worlds/../../etc'],
            'only dots' => ['..'],
            'backslash' => ['..\\\\outside'],
            'windows drive' => ['C:/worlds'],
        ];
    }

    #[DataProvider('unsafeLevelNames')]
    public function test_level_names_leaving_the_server_root_fall_back_to_world(string $value): void
    {
        $this->assertSame('world', ServerProperties::levelName("level-name=$value\n"));
    }

    public function test_get_returns_null_for_an_unset_key(): void
    {
        $this->assertNull(ServerProperties::get("motd=hi\n", 'level-name'));
        $this->assertSame('', ServerProperties::get("level-name\n", 'level-name'));
    }
}
