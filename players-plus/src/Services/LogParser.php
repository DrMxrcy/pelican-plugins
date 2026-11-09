<?php

namespace DrMxrcy\PlayersPlus\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DrMxrcy\PlayersPlus\Enums\LogEventType;

/**
 * Pure: turns server log lines into join, leave and UUID events. No IO, no clock.
 *
 * Supported line shapes (after ANSI and § colour codes are stripped):
 * - vanilla / Fabric:  `[12:00:31] [Server thread/INFO]: Alice joined the game`
 * - Paper / Purpur:    `[12:00:31 INFO]: Alice joined the game`
 * - Forge:             `[12:00:31] [Server thread/INFO] [minecraft/MinecraftServer]: Alice joined the game`
 * - NeoForge:          `[09Nov2026 12:00:31.007] [Server thread/INFO] [net.minecraft.server.MinecraftServer/]: ...`
 * - Fabric (alt):      `[12:00:31] [Server thread/INFO] (Minecraft) Alice joined the game`
 *
 * Log content is tenant-controlled and players can write chat, so the message after the
 * logger header must be exactly the event text: a chat line (`<Bob> Alice joined the game`,
 * `[Bob] ...`, `* Bob ...`) never matches, and names must pass the Minecraft name rules.
 * The `logged in with entity id … from /<ip>` line is never matched, so no IP leaves here.
 */
final class LogParser
{
    /** NeoForge dates outside these years are junk (or forged), not a time to store. */
    public const MIN_YEAR = 2009;

    public const MAX_YEAR = 2100;

    /** Longer lines are stack traces or junk, never an event. */
    public const MAX_LINE_BYTES = 4096;

    /**
     * `[time]` or `[time LEVEL]` (optionally with a NeoForge date and milliseconds), then any
     * number of `[thread/LEVEL]` / `[logger]` blocks separated by spaces only, then `: ` or a
     * Fabric ` (Logger) `, then the message.
     */
    private const LINE = '/^\[(?:(\d{2})([A-Za-z]{3})(\d{4}) )?(\d{2}):(\d{2}):(\d{2})(?:[.,]\d{1,9})?(?: [A-Z]+)?\](?: \[[^\]]*\])*(?:: | \([^)]*\) )(.*)$/D';

    private const JOIN = '/^(\S+)(?: \(formerly known as \S+\))? joined the game$/D';

    private const LEAVE = '/^(\S+) left the game$/D';

    private const UUID = '/^UUID of player (\S+) is ([0-9a-fA-F-]{32,36})$/D';

    /** Null for a line with no leading timestamp (stack traces, blank lines, junk). */
    public static function line(string $raw): ?LogLine
    {
        if (strlen($raw) > self::MAX_LINE_BYTES) {
            return null;
        }

        $line = self::strip($raw);
        if (preg_match(self::LINE, $line, $m) !== 1) {
            return null;
        }

        [$hour, $minute, $second] = [(int) $m[4], (int) $m[5], (int) $m[6]];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $date = null;
        if ($m[1] !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!dMY', $m[1] . $m[2] . $m[3]);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || (int) $parsed->format('Y') < self::MIN_YEAR || (int) $parsed->format('Y') > self::MAX_YEAR) {
                return null;
            }
            $date = $parsed->format('Y-m-d');
        }

        return new LogLine($hour * 3600 + $minute * 60 + $second, $date, self::event($m[7]));
    }

    public static function event(string $message): ?LogEvent
    {
        if (preg_match(self::JOIN, $message, $m) === 1) {
            return MinecraftQuery::isPlayerName($m[1]) ? new LogEvent(LogEventType::Join, $m[1]) : null;
        }

        if (preg_match(self::LEAVE, $message, $m) === 1) {
            return MinecraftQuery::isPlayerName($m[1]) ? new LogEvent(LogEventType::Leave, $m[1]) : null;
        }

        if (preg_match(self::UUID, $message, $m) === 1) {
            $uuid = MinecraftQuery::normalizeUuid($m[2]);

            return MinecraftQuery::isPlayerName($m[1]) && $uuid !== null ? new LogEvent(LogEventType::Uuid, $m[1], $uuid) : null;
        }

        return null;
    }

    /**
     * Dated events from $lines, in log order. The clock advances on every timestamped line
     * (not just events), so a midnight wrap is seen even on a quiet log.
     *
     * @param  iterable<string>  $lines
     * @return list<array{0: CarbonImmutable, 1: LogEvent}>
     */
    public static function events(iterable $lines, LogClock $clock): array
    {
        $events = [];
        foreach ($lines as $raw) {
            $line = self::line($raw);
            if ($line === null) {
                continue;
            }
            $at = $clock->at($line);
            if ($at !== null && $line->event !== null) {
                $events[] = [$at, $line->event];
            }
        }

        return $events;
    }

    /** Drops line endings, ANSI escape sequences and § formatting codes. */
    private static function strip(string $line): string
    {
        $line = rtrim($line, "\r\n");
        $line = (string) preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line);

        return (string) preg_replace('/\xc2\xa7[0-9a-fk-orx]/i', '', $line);
    }
}
