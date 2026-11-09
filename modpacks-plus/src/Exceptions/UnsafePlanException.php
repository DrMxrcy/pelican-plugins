<?php

namespace DrMxrcy\ModpacksPlus\Exceptions;

use RuntimeException;

/**
 * A pack (or one of its files, URLs, hashes or archive entries) broke a safety rule.
 * `reason` is a stable key (lang: modpacks-plus::strings.reasons.<reason>); `detail` is
 * the offending value, cleaned for display.
 */
class UnsafePlanException extends RuntimeException
{
    public readonly string $detail;

    public function __construct(public readonly string $reason, string $detail = '')
    {
        $this->detail = self::clean($detail);

        parent::__construct("Unsafe modpack ($reason)".($this->detail !== '' ? ": {$this->detail}" : ''));
    }

    /** Printable, single-line and short, so a hostile value can't mangle logs or the page. */
    public static function clean(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '?', $value);

        return mb_strimwidth($value, 0, 200, '…');
    }
}
