<?php

namespace DrMxrcy\ModpacksPlus\Support;

/** Size and count limits a pack must stay within (config: modpacks-plus.caps). */
final class Caps
{
    public function __construct(
        public readonly int $maxFiles = 1000,
        public readonly int $maxFileBytes = 256 * 1024 * 1024,
        public readonly int $maxArchiveBytes = 100 * 1024 * 1024,
        public readonly int $maxTotalBytes = 4 * 1024 * 1024 * 1024,
        public readonly int $maxArchiveEntries = 20000,
        public readonly int $maxReadbackBytes = 64 * 1024 * 1024,
    ) {}

    public static function fromConfig(): self
    {
        $caps = config('modpacks-plus.caps', []);
        $caps = is_array($caps) ? $caps : [];
        $defaults = new self();

        $int = fn (string $key, int $default): int => is_numeric($caps[$key] ?? null) && (int) $caps[$key] > 0 ? (int) $caps[$key] : $default;

        return new self(
            maxFiles: $int('max_files', $defaults->maxFiles),
            maxFileBytes: $int('max_file_bytes', $defaults->maxFileBytes),
            maxArchiveBytes: $int('max_archive_bytes', $defaults->maxArchiveBytes),
            maxTotalBytes: $int('max_total_bytes', $defaults->maxTotalBytes),
            maxArchiveEntries: $int('max_archive_entries', $defaults->maxArchiveEntries),
            maxReadbackBytes: $int('max_readback_bytes', $defaults->maxReadbackBytes),
        );
    }
}
