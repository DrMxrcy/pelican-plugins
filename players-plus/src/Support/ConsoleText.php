<?php

namespace DrMxrcy\PlayersPlus\Support;

/**
 * Turns free text typed by a panel user (a kick or ban reason) into one line of printable
 * text that is safe as the tail of a console command: the daemon writes each command to the
 * server's stdin followed by "\n", so any line break would start a second command.
 */
final class ConsoleText
{
    public const MAX_REASON = 100;

    /** Input past this many bytes is dropped before cleaning; it could never fit anyway. */
    private const MAX_INPUT_BYTES = 4096;

    public static function reason(mixed $text): string
    {
        if (!is_string($text) || $text === '') {
            return '';
        }

        // Invalid UTF-8 (including a character cut by the byte cap) becomes "?", so the
        // Unicode-aware patterns below can never fail on it and return null.
        $text = mb_scrub(substr($text, 0, self::MAX_INPUT_BYTES), 'UTF-8');

        // Controls (C0, DEL, C1 incl. NEL), and every separator (U+2028, U+2029, NBSP, tabs
        // turned controls) become a space; invisible format, private-use, unassigned and
        // surrogate code points are dropped.
        $text = (string) preg_replace('/[\p{Cc}\p{Z}]/u', ' ', $text);
        $text = (string) preg_replace('/\p{C}/u', '', $text);

        // Formatting codes (§c, §l, §x...) and any stray section sign.
        $text = (string) preg_replace('/§[0-9A-Za-z]/u', '', $text);
        $text = str_replace('§', '', $text);

        // Never needed in a reason, and the panel's activity log shows the command as HTML.
        $text = str_replace(['<', '>'], '', $text);

        // Vanilla expands entity selectors (@a, @e[...]) inside kick and ban reasons when run
        // from the console; a space after the @ keeps the text and stops the lookup.
        $text = (string) preg_replace('/@(?=[aenprs])/', '@ ', $text);

        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return rtrim(mb_substr($text, 0, self::MAX_REASON, 'UTF-8'));
    }
}
