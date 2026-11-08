<?php

namespace DrMxrcy\ModrinthPlus\Support;

/**
 * Reads values from a Minecraft `server.properties` file (Java properties format).
 */
final class ServerProperties
{
    public const DEFAULT_LEVEL_NAME = 'world';

    /**
     * The world folder named by `level-name`, or "world" when the file is missing, has no
     * usable value, or names something outside the server root (absolute path, `..`).
     */
    public static function levelName(?string $content): string
    {
        $value = $content === null ? null : self::get($content, 'level-name');

        if ($value === null) {
            return self::DEFAULT_LEVEL_NAME;
        }

        $value = trim($value, " \t\n\r\0\x0B/");

        if (
            $value === ''
            || str_contains($value, "\0")
            || str_contains($value, '\\')
            || in_array('..', explode('/', $value), true)
            || preg_match('/^[A-Za-z]:/', $value) === 1
        ) {
            return self::DEFAULT_LEVEL_NAME;
        }

        return $value;
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
                $found = self::unescape(rtrim($matches[2], "\r\n"));
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
