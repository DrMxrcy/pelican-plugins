<?php

namespace DrMxrcy\ModrinthPlus\Support;

/**
 * Reads a Modrinth project's `client_side` / `server_side` support values
 * (`required | optional | unsupported | unknown`). Search hits and `/v2/projects`
 * entries both carry them; rows for projects no longer on Modrinth do not, and are
 * treated as "nothing known".
 */
final class Sides
{
    public const REQUIRED = 'required';

    public const UNSUPPORTED = 'unsupported';

    /**
     * Players must install this project on their client too: it needs the client and
     * is not client-only (a missing or `unknown` server side still counts).
     *
     * @param  array<string, mixed>  $project
     */
    public static function playersNeedIt(array $project): bool
    {
        return self::side($project, 'client_side') === self::REQUIRED
            && !self::isClientOnly($project);
    }

    /**
     * The project does nothing on a server (`server_side = unsupported`).
     *
     * @param  array<string, mixed>  $project
     */
    public static function isClientOnly(array $project): bool
    {
        return self::side($project, 'server_side') === self::UNSUPPORTED;
    }

    /** @param  array<string, mixed>  $project */
    private static function side(array $project, string $key): ?string
    {
        $value = $project[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
