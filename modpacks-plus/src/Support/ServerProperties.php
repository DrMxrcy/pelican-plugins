<?php

namespace DrMxrcy\ModpacksPlus\Support;

/** Minimal server.properties reading: enough to find the world folder. */
final class ServerProperties
{
    /**
     * The raw `level-name` value, or null when the key is absent. Escapes are not
     * decoded on purpose: a value with a backslash is handed on as-is and refused by
     * PlanValidator (fail closed) rather than guessed.
     */
    public static function levelName(string $contents): ?string
    {
        $value = null;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = ltrim($line, " \t\f");
            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            if (!preg_match('/^level-name[ \t\f]*[=:][ \t\f]*(.*)$/', $line, $matches)
                && !preg_match('/^level-name[ \t\f]+(.*)$/', $line, $matches)) {
                continue;
            }

            // Java keeps the last occurrence.
            $value = $matches[1];
        }

        return $value;
    }
}
