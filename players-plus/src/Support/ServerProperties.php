<?php

namespace DrMxrcy\PlayersPlus\Support;

/**
 * Reads values from a Minecraft `server.properties` file (Java properties format).
 * Same parser as Modrinth Plus; copied because plugins must not depend on each other.
 */
final class ServerProperties
{
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
