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

    // server.properties keys kept from the server's own file when a pack ships one (plan 36), comma
    // separated. Unset: the default list (PropertiesPreserver::DEFAULT_KEYS). level-name is always
    // kept and the ports always follow the panel allocation, whatever this says.
    'preserved_properties' => env('MODPACKS_PLUS_PRESERVED_PROPERTIES'),

    // Check installed packs for new versions and notify the server owner once per version (plan 36):
    // off, daily or weekly. Only pack metadata is read from Modrinth/CurseForge; no node is contacted.
    'update_check' => in_array(env('MODPACKS_PLUS_UPDATE_CHECK'), ['off', 'daily', 'weekly'], true) ? env('MODPACKS_PLUS_UPDATE_CHECK') : 'off',

    // Files pulled per download job; cancel takes effect between batches.
    'download_batch' => (int) env('MODPACKS_PLUS_DOWNLOAD_BATCH', 10),

    // From this many override files on, the panel writes them as one zip it builds itself and the
    // node unpacks it (plan 42) instead of one daemon write per file. 0 turns that off.
    'override_archive_min' => (int) env('MODPACKS_PLUS_OVERRIDE_ARCHIVE_MIN', 50),

    // Hard limits checked before anything is written to the server.
    'caps' => [
        // Files listed in the pack index (after dropping client-only ones).
        'max_files' => (int) env('MODPACKS_PLUS_MAX_FILES', 1000),
        // A single downloaded or extracted file.
        'max_file_bytes' => (int) env('MODPACKS_PLUS_MAX_FILE_BYTES', 256 * 1024 * 1024),
        // The .mrpack archive itself (downloaded by the panel to read its index and overrides).
        'max_archive_bytes' => (int) env('MODPACKS_PLUS_MAX_ARCHIVE_BYTES', 100 * 1024 * 1024),
        // Everything written to the server: downloads plus extracted overrides. On a server with a
        // disk limit the pack must also fit its free space minus the headroom below (plan 38);
        // this cap applies on its own when the disk is unlimited or its usage can't be read.
        'max_total_bytes' => (int) env('MODPACKS_PLUS_MAX_TOTAL_BYTES', 20 * 1024 * 1024 * 1024),
        // Space kept free on a disk-limited server: this percent of its limit (0-90), at least
        // disk_headroom_min_mb. Old mods/ and config/ stay on disk while a pack installs.
        'disk_headroom_percent' => (int) env('MODPACKS_PLUS_DISK_HEADROOM_PERCENT', 10),
        'disk_headroom_min_mb' => (int) env('MODPACKS_PLUS_DISK_HEADROOM_MIN_MB', 512),
        // Entries in the .mrpack archive.
        'max_archive_entries' => (int) env('MODPACKS_PLUS_MAX_ARCHIVE_ENTRIES', 20000),
        // Files not vouched for by Modrinth (other hosts, lookup misses) are fetched and hashed by
        // the panel, held in memory and written through the daemon: each may not be larger than this.
        'max_panel_fetch_bytes' => (int) env('MODPACKS_PLUS_MAX_PANEL_FETCH_BYTES', 32 * 1024 * 1024),
        // A single override file (read into memory from the archive before it is written). A
        // CurseForge server pack's mod jars in mods/ are capped by max_file_bytes instead and
        // streamed through a file on the panel.
        'max_override_bytes' => (int) env('MODPACKS_PLUS_MAX_OVERRIDE_BYTES', 16 * 1024 * 1024),
    ],
];
