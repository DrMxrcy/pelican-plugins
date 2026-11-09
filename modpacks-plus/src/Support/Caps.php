<?php

namespace DrMxrcy\ModpacksPlus\Support;

/** Size and count limits a pack must stay within (config: modpacks-plus.caps). */
final class Caps
{
    public function __construct(
        public readonly int $maxFiles = 1000,
        public readonly int $maxFileBytes = 256 * 1024 * 1024,
        public readonly int $maxArchiveBytes = 100 * 1024 * 1024,
        public readonly int $maxTotalBytes = 20 * 1024 * 1024 * 1024,
        public readonly int $maxArchiveEntries = 20000,
        public readonly int $maxPanelFetchBytes = 32 * 1024 * 1024,
        public readonly int $maxOverrideBytes = 16 * 1024 * 1024,
        // Plan 38: space kept free on a disk-limited server (percent of its limit, at least the floor).
        public readonly int $diskHeadroomPercent = 10,
        public readonly int $diskHeadroomMinBytes = 512 * 1024 * 1024,
    ) {}

    public static function fromConfig(): self
    {
        $caps = config('modpacks-plus.caps', []);
        $caps = is_array($caps) ? $caps : [];
        $defaults = new self();

        $int = fn (string $key, int $default): int => is_numeric($caps[$key] ?? null) && (int) $caps[$key] > 0 ? (int) $caps[$key] : $default;
        // Headroom may be 0 (admin's choice); the percent is kept below 100 so a limit always leaves room.
        $zeroOk = fn (string $key, int $default): int => is_numeric($caps[$key] ?? null) && (int) $caps[$key] >= 0 ? (int) $caps[$key] : $default;
        $percent = min(90, $zeroOk('disk_headroom_percent', $defaults->diskHeadroomPercent));
        $minMb = min(1024 * 1024, $zeroOk('disk_headroom_min_mb', intdiv($defaults->diskHeadroomMinBytes, 1024 * 1024)));

        return new self(
            maxFiles: $int('max_files', $defaults->maxFiles),
            maxFileBytes: $int('max_file_bytes', $defaults->maxFileBytes),
            maxArchiveBytes: $int('max_archive_bytes', $defaults->maxArchiveBytes),
            maxTotalBytes: $int('max_total_bytes', $defaults->maxTotalBytes),
            maxArchiveEntries: $int('max_archive_entries', $defaults->maxArchiveEntries),
            maxPanelFetchBytes: $int('max_panel_fetch_bytes', $defaults->maxPanelFetchBytes),
            maxOverrideBytes: $int('max_override_bytes', $defaults->maxOverrideBytes),
            diskHeadroomPercent: $percent,
            diskHeadroomMinBytes: $minMb * 1024 * 1024,
        );
    }
}
