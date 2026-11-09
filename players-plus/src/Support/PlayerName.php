<?php

namespace DrMxrcy\PlayersPlus\Support;

use InvalidArgumentException;

/**
 * The only player names moderation commands accept: a plain Java Edition name, 3 to 16 ASCII
 * letters, digits or underscores. Anything else (spaces, line breaks, `;`, `/`, `§`, `@`
 * selectors, Unicode look-alikes, Floodgate prefixes) is refused, never cleaned up, so the
 * name in a command is always exactly what was validated.
 */
final class PlayerName
{
    /**
     * Byte-wise (no /u), so every non-ASCII byte fails the class; \z rather than $, which
     * would also match before a trailing "\n".
     */
    public const PATTERN = '/^[A-Za-z0-9_]{3,16}\z/';

    public static function isValid(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 16 && preg_match(self::PATTERN, $name) === 1;
    }

    /** @throws InvalidArgumentException when $name is not a valid player name */
    public static function require(mixed $name): string
    {
        if (!self::isValid($name)) {
            throw new InvalidArgumentException('Invalid player name.');
        }

        /** @var string $name */
        return $name;
    }
}
