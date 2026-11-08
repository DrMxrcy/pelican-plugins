<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'latest_minecraft_version' => 'Neueste Minecraft-Version',
        'settings_saved' => 'Einstellungen gespeichert',
    ],

    'page' => [
        'open_folder' => ':folder-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed' => 'Installiert :type',
        'unknown' => 'Unbekannt',
        'view_all' => 'Alle',
        'view_installed' => 'Installiert',
        'mod_unavailable' => 'Dieser Mod/Plugin ist auf Modrinth nicht mehr verfügbar',
    ],

    'client_only' => [
        'show' => 'Client-only anzeigen',
        'hide' => 'Client-only ausblenden',
        'tooltip' => 'Reine Client-Mods und -Plugins (etwa Sodium oder Iris) haben auf einem Server keine Wirkung.',
        'badge' => 'Nur für den Client — wirkt auf einem Server nicht, kann sicher entfernt werden',
        'badge_tooltip' => 'Der Server lädt diese Mod nie; nur Spieler nutzen sie in ihrem eigenen Spiel',
    ],

    'filters' => [
        'sort' => 'Sortieren nach',
        'sort_default' => 'Standard (Relevanz bei Suche, sonst Downloads)',
        'categories' => 'Kategorien',
    ],

    'sort' => [
        'relevance' => 'Relevanz',
        'downloads' => 'Downloads',
        'follows' => 'Follower',
        'newest' => 'Neueste',
        'updated' => 'Zuletzt aktualisiert',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Downloads',
        'published' => 'Veröffentlicht',
        'changelog' => 'Änderungsprotokoll',
        'no_file_found' => 'Keine Datei gefunden',
    ],

    'actions' => [
        'install_latest' => 'Neueste Version installieren',
        'install' => 'Installieren',
        'installed' => 'Installiert',
        'update' => 'Aktualisieren',
        'uninstall' => 'Deinstallieren',
        'versions' => 'Versionsauswahl',
    ],

    'modals' => [
        'update_heading' => 'Mod/Plugin aktualisieren',
        'update_description' => 'Dies ersetzt Version :old_version durch Version :new_version. Die alte Datei wird gelöscht.',
        'uninstall_heading' => 'Mod/Plugin deinstallieren',
        'uninstall_description' => 'Möchtest du :name wirklich deinstallieren? Dies wird die Datei dauerhaft von deinem Server löschen.',
    ],

    'sides' => [
        'players_need_it' => 'Auch für Spieler nötig',
        'players_need_it_tooltip' => 'Spieler müssen diese Mod ebenfalls installieren',
    ],

    'compatibility' => [
        'action' => 'Kompatibilität prüfen',
        'tooltip' => 'Prüft, ob jedes installierte Projekt eine Version für diese Minecraft-Version und diesen Loader hat. Ändert nichts.',
        'heading' => 'Kompatibilitätsprüfung',
        'description' => 'Minecraft :version mit :loader. Diese Prüfung installiert, aktualisiert oder entfernt nichts.',
        'close' => 'Schließen',
        'none_installed' => 'Es ist noch nichts installiert.',
        'all_compatible' => 'Alle :count installierten Projekte haben eine Version für diese Minecraft-Version und diesen Loader.',
        'summary' => ':count von :total installierten Projekten brauchen Aufmerksamkeit:',
        'status' => [
            'needs_update' => 'Update nötig',
            'incompatible' => 'Inkompatibel',
            'unknown' => 'Unbekannt',
        ],
        'details' => [
            'needs_update' => 'Die installierte Version :installed ist nicht für diese Minecraft-Version gebaut, :newest aber schon. Nutze den Update-Button im Tab „Installiert“.',
            'incompatible' => 'Keine Version dieses Projekts unterstützt diese Minecraft-Version und diesen Loader bisher (installiert: :installed).',
            'unknown' => 'Modrinth war für dieses Projekt nicht erreichbar. Versuche es später erneut.',
            'unknown_loader' => 'Der Loader des Servers ist unbekannt, daher kann die Kompatibilität nicht geprüft werden.',
        ],
    ],

    'notifications' => [
        'install_success' => 'Installation abgeschlossen',
        'install_success_body' => ':name Version :version erfolgreich installiert',
        'install_failed' => 'Installation fehlgeschlagen',
        'install_failed_body' => 'Bei der Installation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'update_success' => 'Aktualisierung abgeschlossen',
        'update_success_body' => 'Erfolgreich auf Version :version aktualisiert',
        'update_failed' => 'Aktualisierung fehlgeschlagen',
        'update_failed_body' => 'Bei der Aktualisierung ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'uninstall_success' => 'Deinstallation abgeschlossen',
        'uninstall_success_body' => ':name erfolgreich deinstalliert',
        'uninstall_partial' => 'Deinstallation unvollständig',
        'uninstall_partial_body' => 'Die Datei von :name wurde gelöscht, konnte aber nicht aus der Liste der installierten Mods/Plugins entfernt werden. Sie wird eventuell weiterhin als installiert angezeigt.',
        'uninstall_failed' => 'Deinstallation fehlgeschlagen',
        'uninstall_failed_body' => 'Bei der Deinstallation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
    ],
];
