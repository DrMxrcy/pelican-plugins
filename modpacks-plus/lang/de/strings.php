<?php

return [
    'reasons' => [
        'unsafe_level_name' => 'Der Weltordner (level-name in server.properties) ist kein Ordner im Server: :detail',
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
        'download_failed' => 'Das Pack konnte nicht heruntergeladen werden: :detail',
        'bad_archive' => 'Das Pack-Archiv ist beschädigt oder wird nicht unterstützt: :detail',
        'bad_index' => 'Der Pack-Index fehlt oder ist ungültig: :detail',
        'not_a_modpack' => 'Dieses Projekt ist kein Modpack: :detail',
        'not_found' => 'Nicht bei Modrinth gefunden: :detail',
        'api_unavailable' => 'Modrinth ist nicht erreichbar. Bitte später erneut versuchen. (:detail)',
        'version_mismatch' => 'Diese Version gehört zu einem anderen Pack: :detail',
        'no_pack_file' => 'Diese Version hat keine .mrpack-Datei: :detail',
        'duplicate_path' => 'Das Pack nennt denselben Pfad zweimal: :detail',
    ],
    'settings' => [
        'allow_skip_backup' => 'Überspringen des Backups erlauben',
        'allow_skip_backup_help' => 'Kann ein Server kein Backup anlegen (keine Backup-Plätze, Limit erreicht oder gedrosselt), wird die Installation ohne Backup fortgesetzt. Aus: Die Installation stoppt, bevor etwas geändert wird.',
        'saved' => 'Modpacks-Plus-Einstellungen gespeichert',
    ],
];
