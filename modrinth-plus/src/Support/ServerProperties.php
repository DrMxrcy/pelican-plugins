<?php

namespace DrMxrcy\ModrinthPlus\Support;

use DrMxrcy\ModrinthPlus\Exceptions\InvalidLevelNameException;

/**
 * Reads values from a Minecraft `server.properties` file (Java properties format).
 */
final class ServerProperties
{
    public const DEFAULT_LEVEL_NAME = 'world';

    /**
     * The world folder named by `level-name`. A missing file or a missing/empty `level-name` is
     * Minecraft's default, "world". A value that is not a plain folder inside the server root
     * (absolute path, `.` or `..` segment, empty segment, backslash, drive letter, control
     * character, whitespace around a segment) throws: falling back to "world" there would install
     * into, or delete from, a world the server does not use. The value is never trimmed or
     * otherwise altered, except that one trailing `/` is dropped.
     *
     * @throws InvalidLevelNameException
     */
    public static function levelName(?string $content): string
    {
        $value = $content === null ? null : self::get($content, 'level-name');

        if ($value === null || $value === '') {
            return self::DEFAULT_LEVEL_NAME;
        }

        $name = str_ends_with($value, '/') ? substr($value, 0, -1) : $value;

        if (
            $name === ''
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || preg_match('/^[A-Za-z]:/', $name) === 1
        ) {
            throw new InvalidLevelNameException('level-name in server.properties is not a folder inside the server');
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment !== trim($segment)) {
                throw new InvalidLevelNameException('level-name in server.properties is not a folder inside the server');
            }
        }

        return $name;
    }

    /**
     * The value of $key, or null when it is not set. Handles comments (`#`, `!`), the `=`, `:`
     * and whitespace separators and the backslash escapes Java writes (`\:`, `\=`, `\uXXXX`...).
     * Line continuations are not supported; Minecraft never writes them.
     */
    public static function get(string $content, string $key): ?string
    {
        $found = null;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = ltrim($line, " \t\f");

            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            // The key ends at the first unescaped '=', ':' or whitespace.
            if (preg_match('/^((?:\\\\.|[^=:\s\\\\])*)\s*[=:]?\s*(.*)$/s', $line, $matches) !== 1) {
                continue;
            }

            if (self::unescape($matches[1]) === $key) {
                // The last occurrence wins, like java.util.Properties.
                // Trailing whitespace belongs to the value (java.util.Properties keeps it).
                $found = self::unescape($matches[2]);
            }
        }

        return $found;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/s', function (array $matches) {
            $escaped = $matches[1];

            if (strlen($escaped) === 5) {
                // A lone surrogate has no UTF-8 form; mb_chr() then gives false, which becomes ''.
                return (string) mb_chr((int) hexdec(substr($escaped, 1)), 'UTF-8');
            }

            return match ($escaped) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'f' => "\f",
                default => $escaped,
            };
        }, $value);
    }
}
