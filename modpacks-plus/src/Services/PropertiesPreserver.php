<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Merges a pack's `server.properties` into the server's own (plan 36). Pure.
 *
 * The server's file is the base: its comments, blank lines, key order and keys the pack
 * doesn't know are kept as they are. Then:
 * - a key in the preserved list keeps the server's value (when the server has the key);
 * - `level-name` never comes from the pack: it says where the world is, and a pack naming it
 *   (`mods`, `config`, `../x`) could make a pack folder the world or move the world out of the
 *   protected set. The server's value is kept; without one the pack's line is dropped (the
 *   server then uses its default, `world`). A level-name that is written is checked like a world
 *   path (relative, no `..` or backslash) and may not be a pack or move-aside folder
 *   (`unsafe_level_name`);
 * - the port keys (`server-port`, `query.port`) always follow the panel allocation; without one
 *   the server's values are kept and the pack's never used;
 * - every other key the pack sets takes the pack's value; keys only the pack has are appended.
 *
 * Java properties allow escaped keys and line continuations, which could hide or fake a key.
 * Like ServerProperties, either in either file fails closed (`properties_unmergeable`): the
 * caller then leaves the server's file untouched.
 */
final class PropertiesPreserver
{
    /** Kept from the server's file by default (admin-editable, plugin settings). */
    public const DEFAULT_KEYS = [
        'level-name', 'level-seed', 'motd', 'max-players', 'white-list', 'enforce-whitelist', 'online-mode', 'difficulty',
        'gamemode', 'pvp', 'view-distance', 'simulation-distance', 'spawn-protection', 'enable-query', 'query.port', 'server-port',
    ];

    /** Always kept, whatever the admin list says. */
    public const ALWAYS_KEYS = ['level-name'];

    /** Always set to the server's allocation port. */
    public const PORT_KEYS = ['server-port', 'query.port'];

    /** Top-level folders a world may never be named (pack folders the install moves, the plugin's own). */
    public const NOT_WORLD_FOLDERS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', '.modpacks-plus'];

    /** A key the admin list may contain. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._\-]{1,64}$/';

    public const MAX_KEYS = 100;

    /** Largest server.properties read or written (either file). */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * The admin's list (a list, or a comma/newline separated string), reduced to valid unique keys.
     *
     * @return array<int, string>
     */
    public static function keys(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = preg_split('/[\s,]+/', $configured) ?: [];
        }
        if (!is_array($configured)) {
            return self::DEFAULT_KEYS;
        }

        $keys = [];
        foreach ($configured as $key) {
            if (is_string($key) && preg_match(self::KEY_PATTERN, trim($key))) {
                $keys[trim($key)] = true;
            }
        }

        return array_slice(array_keys($keys), 0, self::MAX_KEYS);
    }

    /**
     * Lines of a properties file: `pair` lines with their key and raw value, anything else kept
     * verbatim.
     *
     * @return array<int, array{type: string, raw: string, key: ?string, value: ?string}>
     *
     * @throws UnsafePlanException `properties_unmergeable`
     */
    public static function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new UnsafePlanException('properties_unmergeable', 'too large');
        }

        $raw = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        if ($raw !== [] && end($raw) === '') {
            array_pop($raw);
        }

        $lines = [];
        foreach ($raw as $line) {
            $trimmed = ltrim($line, " \t\f");
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === '!') {
                $lines[] = ['type' => 'other', 'raw' => $line, 'key' => null, 'value' => null];

                continue;
            }

            if (preg_match('/(\\\\+)$/', $trimmed, $trailing) && strlen($trailing[1]) % 2 === 1) {
                throw new UnsafePlanException('properties_unmergeable', 'line continuation');
            }

            preg_match('/^((?:\\\\.|[^=:\s\\\\])*)/', $trimmed, $key);
            if (str_contains($key[1], '\\')) {
                throw new UnsafePlanException('properties_unmergeable', 'escaped key');
            }
            if ($key[1] === '') {
                // An empty key: nothing a merge needs, kept as it is.
                $lines[] = ['type' => 'other', 'raw' => $line, 'key' => null, 'value' => null];

                continue;
            }

            $value = (string) preg_replace('/^[ \t\f]*[=:]?[ \t\f]*/', '', substr($trimmed, strlen($key[1])));
            $lines[] = ['type' => 'pair', 'raw' => $line, 'key' => $key[1], 'value' => $value];
        }

        return $lines;
    }

    /**
     * Key => value, the last occurrence winning (as Java reads it).
     *
     * @return array<string, string>
     *
     * @throws UnsafePlanException
     */
    public static function values(string $contents): array
    {
        $values = [];
        foreach (self::parse($contents) as $line) {
            if ($line['type'] === 'pair') {
                $values[(string) $line['key']] = (string) $line['value'];
            }
        }

        return $values;
    }

    /**
     * The merged file (see the class comment).
     *
     * @param  string  $current  the server's server.properties ('' when it has none)
     * @param  string  $incoming  the pack's server.properties
     * @param  array<int, string>  $preserved  keys kept from $current
     * @param  int|null  $port  the allocation port, or null to keep the server's port values
     *
     * @throws UnsafePlanException `properties_unmergeable`
     */
    public static function merge(string $current, string $incoming, array $preserved, ?int $port): string
    {
        $base = self::parse($current);
        $baseValues = self::values($current);
        $incomingLines = self::parse($incoming);
        $incomingValues = self::values($incoming);

        $forced = $port !== null && $port > 0 && $port <= 65535 ? array_fill_keys(self::PORT_KEYS, (string) $port) : [];
        // Never from the pack: level-name always, the ports when there is no allocation port.
        $serverOnly = array_flip(array_merge(self::ALWAYS_KEYS, $forced === [] ? self::PORT_KEYS : []));
        $keep = array_flip($preserved) + $serverOnly;

        $final = function (string $key) use ($keep, $forced, $baseValues, $incomingValues): string {
            return match (true) {
                isset($forced[$key]) => $forced[$key],
                isset($keep[$key]) && array_key_exists($key, $baseValues) => $baseValues[$key],
                // Server-only keys the server lacks are not written (see the loops below).
                array_key_exists($key, $incomingValues) => $incomingValues[$key],
                default => $baseValues[$key] ?? '',
            };
        };

        $out = [];
        $seen = [];
        foreach ($base as $line) {
            if ($line['type'] !== 'pair') {
                $out[] = $line['raw'];

                continue;
            }

            $key = (string) $line['key'];
            $seen[$key] = true;
            $value = $final($key);
            // Untouched lines stay byte for byte (spacing, separator).
            $out[] = $value === $line['value'] ? $line['raw'] : $key.'='.$value;
        }

        foreach ($incomingLines as $line) {
            $key = (string) $line['key'];
            if ($line['type'] === 'pair' && !isset($seen[$key]) && !isset($serverOnly[$key])) {
                $seen[$key] = true;
                $out[] = $key.'='.$final($key);
            }
        }

        foreach ($forced as $key => $value) {
            if (!isset($seen[$key])) {
                $out[] = $key.'='.$value;
            }
        }

        if (array_key_exists('level-name', $baseValues)) {
            self::assertLevelName($baseValues['level-name']);
        }

        $eol = str_contains($current, "\r\n") ? "\r\n" : "\n";
        $merged = implode($eol, $out).$eol;
        if (strlen($merged) > self::MAX_BYTES) {
            throw new UnsafePlanException('properties_unmergeable', 'too large');
        }

        return $merged;
    }

    /**
     * A level-name that may be written: a relative world path (no `..`, not absolute, no
     * backslash or control character) whose first folder is not a pack or move-aside folder.
     *
     * @throws UnsafePlanException `unsafe_level_name`
     */
    public static function assertLevelName(string $value): void
    {
        $first = strtolower(explode('/', $value)[0]);
        if ($value === '' || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/', $value)
            || !PlanValidator::isSafeRelativePath($value) || in_array($first, self::NOT_WORLD_FOLDERS, true)) {
            throw new UnsafePlanException('unsafe_level_name', $value);
        }
    }
}
