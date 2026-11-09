<?php

return [
    'reasons' => [
        'unsafe_path' => 'The pack contains a path outside the server folder: :detail',
        'protected_path' => 'The pack tries to write a protected file or world: :detail',
        'no_allowed_url' => 'The pack has no allowed HTTPS download for: :detail',
        'bad_hash' => 'The pack has no usable sha512 or sha1 hash for: :detail',
        'hash_mismatch' => 'A downloaded file does not match its hash: :detail',
        'too_many_files' => 'The pack has too many files (:detail).',
        'file_too_large' => 'A file in the pack is too large: :detail',
        'total_too_large' => 'The pack is too large in total (:detail bytes).',
        'archive_too_large' => 'The pack archive is too large: :detail',
        'too_many_entries' => 'The pack archive has too many entries (:detail).',
        'readback_too_large' => 'A file not hosted on Modrinth is too large to verify: :detail',
        'duplicate_path' => 'The pack lists the same path twice: :detail',
    ],
    'settings' => [
        'allow_skip_backup' => 'Allow skipping the backup',
        'allow_skip_backup_help' => 'When a server cannot take a backup (no backup slots, limit reached or throttled), continue the install without one. Off: the install stops before changing anything.',
        'saved' => 'Modpacks Plus settings saved',
    ],
];
