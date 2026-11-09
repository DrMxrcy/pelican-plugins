<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;

/** Minimal server.properties reading: enough to find the world folder, or refuse. */
final class ServerProperties
{
    /**
     * The raw `level-name` value, or null when the key is absent.
     *
     * Java properties allow escaped keys (`level\-name`) and line continuations (a line
     * ending in an odd number of backslashes), which could hide or fake the key. Rather
     * than reimplement that grammar, any continuation or escaped key fails closed. Escapes
     * in the value itself are handed on as-is; PlanValidator refuses a backslash there.
     *
     * @throws UnsafePlanException `unsafe_level_name`
     */
    public static function levelName(string $contents): ?string
    {
        $value = null;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = ltrim($line, " \t\f");
            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            if (preg_match('/(\\\\+)$/', $line, $trailing) && strlen($trailing[1]) % 2 === 1) {
                throw new UnsafePlanException('unsafe_level_name', 'server.properties uses line continuations');
            }

            // The key runs to the first unescaped separator (=, : or whitespace).
            preg_match('/^((?:\\\\.|[^=:\s\\\\])*)/', $line, $key);
            if (str_contains($key[1], '\\')) {
                throw new UnsafePlanException('unsafe_level_name', 'server.properties uses escaped keys');
            }

            if ($key[1] !== 'level-name') {
                continue;
            }

            // Java keeps the last occurrence; whitespace and one separator follow the key.
            $value = (string) preg_replace('/^[ \t\f]*[=:]?[ \t\f]*/', '', substr($line, strlen('level-name')));
        }

        return $value;
    }
}
