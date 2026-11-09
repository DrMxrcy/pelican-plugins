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
        'rollup_retention_days' => 'Stundenverlauf behalten für',
        'rollup_retention_days_help' => 'Stündliche Durchschnitte und Spitzen für die 7- und 30-Tage-Diagramme und die Heatmap. Ältere Stunden werden einmal täglich gelöscht.',
        'minutes' => 'Minuten',
        'days' => 'Tage',
        'saved' => 'Einstellungen gespeichert',
    ],

    'activity' => [
        'navigation' => 'Aktivität',
        'title' => 'Spieleraktivität',
        'none' => '—',
        'peak_today' => 'Höchststand heute',
        'peak_week' => 'Höchststand diese Woche',
        'peak_all_time' => 'Höchststand insgesamt',
        'peak_all_time_help' => 'So weit der Stundenverlauf zurückreicht',
        'average_7d' => '7-Tage-Durchschnitt',
        'average_7d_help' => 'Nur während der Server online war',
        'chart_heading' => 'Spieler online',
        'chart_gaps' => 'Lücken bedeuten: Der Server war offline, hat nicht geantwortet oder wurde nicht abgefragt.',
        'series_online' => 'Spieler online',
        'series_average' => 'Spieler online im Durchschnitt',
        'range' => [
            '24h' => 'Letzte 24 Stunden',
            '7d' => 'Letzte 7 Tage',
            '30d' => 'Letzte 30 Tage',
        ],
        'heatmap_heading' => 'Stoßzeiten',
        'heatmap_description' => 'Spieler online im Durchschnitt nach Wochentag und Stunde, letzte :days Tage (:timezone). Leere Felder: keine Daten oder der Server war offline.',
        'heatmap_cell' => ':time: durchschnittlich :count Spieler',
        'heatmap_empty_cell' => ':time: keine Daten',
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

    'profile' => [
        'identity' => 'Identität',
        'uuid' => 'UUID',
        'uuid_unknown' => 'Noch nicht bekannt',
        'previous_names' => 'Frühere Namen',
        'none' => 'Keine',
        'activity' => 'Aktivität',
        'status' => 'Status',
        'last_seen' => 'Zuletzt gesehen :time',
        'total_playtime' => 'Gesamte Spielzeit',
        'week_playtime' => 'Letzte 7 Tage',
        'sessions' => 'Sitzungen',
        'started' => 'Beigetreten',
        'ended' => 'Verlassen',
        'duration' => 'Dauer',
        'source' => 'Quelle',
        'no_sessions' => 'Noch keine Sitzungen',
    ],

    'stats' => [
        'heading' => 'Statistiken',
        'description' => 'Aus der Statistikdatei der Welt, alle 10 Minuten aktualisiert.',
        'play_time' => 'Spielzeit',
        'deaths' => 'Tode',
        'mob_kills' => 'Getötete Mobs',
        'player_kills' => 'Getötete Spieler',
        'distance_walked' => 'Gelaufene Strecke',
        'jumps' => 'Sprünge',
        'none' => 'Noch keine Statistiken. Der Server schreibt sie, wenn der Spieler geht oder die Welt gespeichert wird.',
        'unavailable' => 'Die Statistikdatei konnte gerade nicht gelesen werden. Versuche es in einer Minute erneut.',
        'no_permission' => 'Statistiken sehen nur Benutzer, die die Dateien des Servers lesen dürfen.',
    ],

    'widget' => [
        'label' => 'Spieler online',
        'no_data' => 'Noch keine Daten',
    ],
];
