<?php

return [
    'navigation' => 'Spieler',

    'settings' => [
        'enabled' => 'Minecraft-Server abfragen',
        'enabled_help' => 'Ausschalten, um die Abfragen zu stoppen. Gespeicherte Daten bleiben sichtbar.',
        'poll_interval' => 'Abfrageintervall',
        'poll_interval_help' => 'Wie oft jeder laufende Minecraft-Server gefragt wird, wer online ist (1 bis 5 Minuten).',
        'retention_days' => 'Messwerte behalten für',
        'retention_days_help' => 'Ältere Messwerte werden einmal täglich gelöscht.',
        'minutes' => 'Minuten',
        'days' => 'Tage',
        'saved' => 'Einstellungen gespeichert',
    ],

    'status' => [
        'online' => 'Online',
        'offline' => 'Offline',
        'unreachable' => 'Keine Antwort',
    ],

    'source' => [
        'poll' => 'Abfrage',
        'log' => 'Log',
    ],

    'page' => [
        'title' => 'Spieler',
        'online_now' => 'Jetzt online',
        'count' => ':online / :max',
        'updated' => 'Aktualisiert :time',
        'partial' => 'Server List Ping zeigt höchstens :limit Spieler. Setze enable-query=true in server.properties, um alle zu sehen.',
        'name' => 'Name',
        'empty' => 'Niemand ist online',
        'no_data_title' => 'Noch keine Spielerdaten',
        'no_data_body' => 'Der Server wird alle :minutes Minute(n) geprüft. Spieler erscheinen hier nach der ersten Prüfung.',
        'offline_title' => 'Der Server ist offline',
        'offline_body' => 'Zuletzt geprüft :time. Spieler erscheinen hier, sobald der Server läuft.',
        'unreachable_title' => 'Der Server hat nicht geantwortet',
        'unreachable_body' => 'Zuletzt geprüft :time. Der Server läuft, hat aber nicht geantwortet. Prüfe, ob das Panel seinen Spielport erreichen kann.',
        'stale_title' => 'Spielerdaten sind veraltet',
        'stale_body' => 'Die letzte Prüfung war :time. Prüfe, ob Queue-Worker und Scheduler des Panels laufen.',
    ],

    'tabs' => [
        'online' => 'Online',
        'all' => 'Alle Spieler',
    ],

    'all' => [
        'heading' => 'Alle Spieler',
        'last_seen' => 'Zuletzt gesehen',
        'online' => 'Online',
        'playtime' => 'Spielzeit',
        'sessions' => 'Sitzungen',
        'first_seen' => 'Zuerst gesehen',
        'empty' => 'Noch keine Spieler gesehen',
        'partial' => 'Unvollständige Liste: Server List Ping zeigt höchstens 12 Spieler. Setze enable-query=true in server.properties für vollständiges Tracking.',
    ],

    'widget' => [
        'label' => 'Spieler online',
        'no_data' => 'Noch keine Daten',
    ],
];
