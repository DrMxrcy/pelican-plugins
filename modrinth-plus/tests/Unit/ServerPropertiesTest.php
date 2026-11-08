<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Exceptions\InvalidLevelNameException;
use DrMxrcy\ModrinthPlus\Exceptions\ServerPropertiesUnreadableException;
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

    public function test_nested_folder_is_kept_without_a_trailing_slash(): void
    {
        $this->assertSame('worlds/main', ServerProperties::levelName("level-name=worlds/main/\n"));
    }

    public function test_trailing_spaces_are_part_of_the_value(): void
    {
        // Java properties keep trailing whitespace; only the line terminator and the leading
        // whitespace before the value are dropped.
        $this->assertSame('world  ', ServerProperties::get("level-name=  world  \r\n", 'level-name'));
        $this->assertSame("world\t", ServerProperties::get("level-name=world\t\n", 'level-name'));
    }

    /** @return array<string, array{string}> */
    public static function unsafeLevelNames(): array
    {
        return [
            'parent folder' => ['../outside'],
            'parent in the middle' => ['worlds/../../etc'],
            'only dots' => ['..'],
            'current folder' => ['.'],
            'current folder segment' => ['worlds/./main'],
            'absolute' => ['/worlds/main/'],
            'empty segment' => ['worlds//main'],
            'backslash' => ['..\\\\outside'],
            'windows drive' => ['C:/worlds'],
            'NUL (escaped)' => ['world\\u0000'],
            'control character (escaped)' => ['world\\n'],
            'trailing space' => ['world '],
            'trailing space in a segment' => ['worlds /main'],
            'escaped leading space' => ['\\ world'],
        ];
    }

    #[DataProvider('unsafeLevelNames')]
    public function test_unsafe_level_names_are_refused_not_replaced_by_world(string $value): void
    {
        // Fails if: an unsafe level-name silently falls back to "world" (or is trimmed into a
        // different folder) instead of leaving the world folder unknown.
        try {
            $name = ServerProperties::levelName("level-name=$value\n");
            $this->fail("Unsafe level-name accepted as [$name].");
        } catch (InvalidLevelNameException $exception) {
            $this->assertInstanceOf(ServerPropertiesUnreadableException::class, $exception);
        }
    }

    public function test_get_returns_null_for_an_unset_key(): void
    {
        $this->assertNull(ServerProperties::get("motd=hi\n", 'level-name'));
        $this->assertSame('', ServerProperties::get("level-name\n", 'level-name'));
    }
}
