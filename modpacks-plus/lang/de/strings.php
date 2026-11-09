<?php

return [
    'reasons' => [
        'unsafe_path' => 'Das Pack enthält einen Pfad außerhalb des Serverordners: :detail',
        'protected_path' => 'Das Pack versucht, eine geschützte Datei oder Welt zu schreiben: :detail',
        'no_allowed_url' => 'Das Pack hat keinen erlaubten HTTPS-Download für: :detail',
        'bad_hash' => 'Das Pack hat keinen brauchbaren sha512- oder sha1-Hash für: :detail',
        'hash_mismatch' => 'Eine heruntergeladene Datei passt nicht zu ihrem Hash: :detail',
        'too_many_files' => 'Das Pack hat zu viele Dateien (:detail).',
        'file_too_large' => 'Eine Datei im Pack ist zu groß: :detail',
        'total_too_large' => 'Das Pack ist insgesamt zu groß (:detail Bytes).',
        'archive_too_large' => 'Das Pack-Archiv ist zu groß: :detail',
        'too_many_entries' => 'Das Pack-Archiv hat zu viele Einträge (:detail).',
        'readback_too_large' => 'Eine nicht bei Modrinth gehostete Datei ist zu groß zum Prüfen: :detail',
        'duplicate_path' => 'Das Pack nennt denselben Pfad zweimal: :detail',
    ],
    'settings' => [
        'allow_skip_backup' => 'Überspringen des Backups erlauben',
        'allow_skip_backup_help' => 'Kann ein Server kein Backup anlegen (keine Backup-Plätze, Limit erreicht oder gedrosselt), wird die Installation ohne Backup fortgesetzt. Aus: Die Installation stoppt, bevor etwas geändert wird.',
        'saved' => 'Modpacks-Plus-Einstellungen gespeichert',
    ],
];
