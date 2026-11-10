<?php

namespace DrMxrcy\ModpacksPlus\Providers\CurseForge;

/**
 * Reads what a user pastes to open a CurseForge modpack: a numeric Project ID, a modpack page link
 * (`https://www.curseforge.com/minecraft/modpacks/<slug>`, with or without `/files/<id>` and the like)
 * or a project link (`https://www.curseforge.com/projects/<id>`).
 *
 * Threat handled: the pasted text is never fetched or used as a URL. Only an integer project id or a
 * slug matching a strict lowercase pattern comes out; the caller builds an api.curseforge.com path
 * (or query) from it. Anything else (other hosts, other games, mods, resource packs, schemes like
 * `javascript:`, credentials or ports in the URL, percent-encoding, non-ASCII, ids outside 1..2^31-1)
 * gives null.
 */
final class CurseForgePackLink
{
    /** Longest input looked at. */
    public const MAX_LENGTH = 512;

    /** CurseForge project slugs: lowercase letters, digits, - and _. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/D';

    private const HOST = '(?:https?://)?(?:www\.|legacy\.)?curseforge\.com';

    /** Trailing parts of a page link (tabs, a file, a download); ignored. */
    private const TAIL = '(?:/[A-Za-z0-9_-]{1,40}){0,3}/?(?:[?#][\x21-\x7e]*)?';

    /**
     * @return array{id: string}|array{slug: string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > self::MAX_LENGTH || preg_match('/[^\x21-\x7e]/', $input)) {
            return null;
        }

        if (CurseForgePackProvider::isValidId($input)) {
            return ['id' => $input];
        }

        if (preg_match('~^'.self::HOST.'/projects/([0-9]{1,10})'.self::TAIL.'$~iD', $input, $match)) {
            return CurseForgePackProvider::isValidId($match[1]) ? ['id' => $match[1]] : null;
        }

        if (preg_match('~^'.self::HOST.'/minecraft/modpacks/([A-Za-z0-9_-]{1,100})'.self::TAIL.'$~iD', $input, $match)) {
            $slug = strtolower($match[1]);

            return preg_match(self::SLUG_PATTERN, $slug) ? ['slug' => $slug] : null;
        }

        return null;
    }
}
