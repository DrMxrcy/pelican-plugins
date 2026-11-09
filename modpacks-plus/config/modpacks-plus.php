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
        // Files not vouched for by Modrinth (other hosts, lookup misses) are fetched and hashed by
        // the panel, held in memory and written through the daemon: each may not be larger than this.
        'max_panel_fetch_bytes' => (int) env('MODPACKS_PLUS_MAX_PANEL_FETCH_BYTES', 32 * 1024 * 1024),
        // A single override file (read into memory from the archive before it is written).
        'max_override_bytes' => (int) env('MODPACKS_PLUS_MAX_OVERRIDE_BYTES', 16 * 1024 * 1024),
    ],
];
