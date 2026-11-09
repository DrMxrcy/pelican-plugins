<?php

return [
    // Let an install continue without a backup when the server cannot take one (limit 0,
    // limit reached, throttled). Off by default: the install stops before any change instead.
    'allow_skip_backup' => (bool) env('MODPACKS_PLUS_ALLOW_SKIP_BACKUP', false),

    // Files pulled per download job; cancel takes effect between batches.
    'download_batch' => (int) env('MODPACKS_PLUS_DOWNLOAD_BATCH', 10),

    // Hard limits checked before anything is written to the server.
    'caps' => [
        // Files listed in the pack index (after dropping client-only ones).
        'max_files' => (int) env('MODPACKS_PLUS_MAX_FILES', 1000),
        // A single downloaded or extracted file.
        'max_file_bytes' => (int) env('MODPACKS_PLUS_MAX_FILE_BYTES', 256 * 1024 * 1024),
        // The .mrpack archive itself (downloaded by the panel to read its index and overrides).
        'max_archive_bytes' => (int) env('MODPACKS_PLUS_MAX_ARCHIVE_BYTES', 100 * 1024 * 1024),
        // Everything written to the server: downloads plus extracted overrides.
        'max_total_bytes' => (int) env('MODPACKS_PLUS_MAX_TOTAL_BYTES', 4 * 1024 * 1024 * 1024),
        // Entries in the .mrpack archive.
        'max_archive_entries' => (int) env('MODPACKS_PLUS_MAX_ARCHIVE_ENTRIES', 20000),
        // Files that can only be verified by reading them back through the panel (not hosted
        // on Modrinth's CDN) may not be larger than this.
        'max_readback_bytes' => (int) env('MODPACKS_PLUS_MAX_READBACK_BYTES', 64 * 1024 * 1024),
    ],
];
