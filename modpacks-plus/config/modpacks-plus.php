<?php

return [
    // Let an install continue without a backup when the server cannot take one (limit 0,
    // limit reached, throttled). Off by default: the install stops before any change instead.
    'allow_skip_backup' => (bool) env('MODPACKS_PLUS_ALLOW_SKIP_BACKUP', false),

    // Switching a server's loader changes its egg and reinstalls it, which Pelican itself only
    // lets admins do. Off: only admins (with "update server" on it) may switch. On: anyone
    // holding the install permissions (settings.reinstall, file.create, file.delete, backup.create).
    'allow_user_loader_switch' => (bool) env('MODPACKS_PLUS_ALLOW_USER_LOADER_SWITCH', false),

    // The egg (id) a loader switch moves a server onto when several official eggs match. Empty:
    // picked automatically (the one egg updated from pelican-eggs/minecraft), refused if ambiguous.
    'loader_eggs' => [
        'fabric' => is_numeric(env('MODPACKS_PLUS_EGG_FABRIC')) ? (int) env('MODPACKS_PLUS_EGG_FABRIC') : null,
        'forge' => is_numeric(env('MODPACKS_PLUS_EGG_FORGE')) ? (int) env('MODPACKS_PLUS_EGG_FORGE') : null,
        'neoforge' => is_numeric(env('MODPACKS_PLUS_EGG_NEOFORGE')) ? (int) env('MODPACKS_PLUS_EGG_NEOFORGE') : null,
        'quilt' => is_numeric(env('MODPACKS_PLUS_EGG_QUILT')) ? (int) env('MODPACKS_PLUS_EGG_QUILT') : null,
    ],

    // CurseForge packs (plan 31). The API key comes from console.curseforge.com and is only ever
    // sent to api.curseforge.com; it is never shown again, logged or stored with an install.
    'curseforge' => [
        'api_key' => is_string(env('MODPACKS_PLUS_CURSEFORGE_API_KEY')) && env('MODPACKS_PLUS_CURSEFORGE_API_KEY') !== '' ? (string) env('MODPACKS_PLUS_CURSEFORGE_API_KEY') : null,
        // Without a key: use the public mirror https://api.curse.tools/v1 for metadata (search, pack
        // and file info). Off by default; it runs on someone else's key, may be rate-limited or gone
        // and may not be allowed by CurseForge's API terms. A configured key always wins.
        'mirror' => (bool) env('MODPACKS_PLUS_CURSEFORGE_MIRROR', false),
    ],

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
