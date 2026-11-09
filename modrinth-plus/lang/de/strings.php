<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',
    'minecraft_datapacks' => 'Minecraft-Datenpakete',

    'settings' => [
        'latest_minecraft_version' => 'Neueste Minecraft-Version',
        'settings_saved' => 'Einstellungen gespeichert',
    ],

    'page' => [
        'open_folder' => ':folder-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed' => 'Installierte :type',
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

    'changelog' => [
        'none' => 'Kein Änderungsprotokoll angegeben',
        'more' => ':count weitere auf Modrinth',
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
        'banner' => [
            'heading' => ':count installiertes Projekt unterstützt Minecraft :version nicht|:count installierte Projekte unterstützen Minecraft :version nicht',
            'description' => 'Installierte Versionen ohne Build für diese Minecraft-Version und :loader laden eventuell nicht. Prüfe die Kompatibilität, um zu sehen, welche betroffen sind und ob ein Update hilft.',
            'pinned' => ':count davon ist angepinnt, daher wird dafür kein Update angeboten, bis es gelöst wird.|:count davon sind angepinnt, daher wird dafür kein Update angeboten, bis sie gelöst werden.',
        ],
        'pinned_label' => ':name (angepinnt)',
        'status' => [
            'needs_update' => 'Update nötig',
            'incompatible' => 'Inkompatibel',
            'unknown' => 'Unbekannt',
        ],
        'details' => [
            'needs_update' => 'Die installierte Version :installed ist nicht für diese Minecraft-Version gebaut, :newest aber schon. Nutze den Update-Button im Tab „Installiert“.',
            'needs_update_pinned' => 'Die installierte Version :installed ist nicht für diese Minecraft-Version gebaut, :newest aber schon. Dieses Projekt ist angepinnt: Löse es im Tab „Installiert“, um es zu aktualisieren.',
            'incompatible' => 'Keine Version dieses Projekts unterstützt diese Minecraft-Version und diesen Loader bisher (installiert: :installed).',
            'unknown' => 'Modrinth war für dieses Projekt nicht erreichbar. Versuche es später erneut.',
            'unknown_loader' => 'Der Loader des Servers ist unbekannt, daher kann die Kompatibilität nicht geprüft werden.',
        ],
    ],

    'scan' => [
        'action' => 'Ordner scannen',
        'tooltip' => 'Von Hand hinzugefügte Jars finden und auf Modrinth nachschlagen',
        'heading' => 'Ordner scannen',
        'description' => 'Sucht in :folder nach Jars, die nicht in der Liste der installierten Projekte stehen, prüft jede anhand ihres Datei-Hashes auf Modrinth und fügt die Treffer hinzu, damit sie hier aktualisiert und deinstalliert werden können. Jars werden nur gelesen: nichts wird gelöscht, umbenannt oder ersetzt. Große Ordner können einen Moment dauern.',
        'submit' => 'Scannen',
        'finished' => 'Scan abgeschlossen',
        'finished_with_problems' => 'Scan mit Problemen abgeschlossen',
        'nothing_new' => 'Keine neuen Jars gefunden.',
        'imported' => ':count importiert: :list.',
        'not_on_modrinth' => 'Nicht auf Modrinth (:count): :list.',
        'duplicates' => 'Unverändert gelassen, das Projekt ist bereits aus einer anderen Datei installiert (:count): :list.',
        'skipped' => 'Übersprungen, zu groß oder nicht lesbar (:count): :list.',
        'error' => 'Modrinth war nicht erreichbar oder die Liste der installierten Projekte konnte nicht gelesen oder gespeichert werden. Versuche es später erneut.',
        'failed' => 'Scan fehlgeschlagen',
        'failed_body' => 'Der Ordner konnte nicht gelesen werden. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'badge' => 'Nicht auf Modrinth',
        'badge_tooltip' => 'Diese Jar wurde von Hand hinzugefügt und ist Modrinth nicht bekannt, daher kann sie hier nicht aktualisiert werden.',
        'row_description' => 'Von Hand hinzugefügt. Hier ist nur Deinstallieren möglich.',
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

    'pin' => [
        'pin_tooltip' => 'Anpinnen: diese Version behalten, keine Updates anbieten',
        'unpin_tooltip' => 'Lösen: wieder Updates anbieten',
        'badge' => 'Angepinnt',
        'badge_tooltip' => 'Bleibt bei dieser Version: Es wird kein Update angeboten und „Alle aktualisieren“ überspringt es. Eine bestimmte Version zu installieren geht weiterhin.',
        'pinned' => 'Angepinnt',
        'pinned_body' => ':name bleibt bei der aktuellen Version, bis du es löst.',
        'unpinned' => 'Gelöst',
        'unpinned_body' => 'Für :name werden wieder Updates angeboten.',
        'failed' => 'Anpinnen konnte nicht geändert werden',
        'failed_body' => 'Die Liste der installierten Projekte konnte nicht gelesen oder gespeichert werden, daher wurde für :name nichts geändert. Versuche es später erneut.',
    ],

    'update_all' => [
        'action' => 'Alle aktualisieren (:count)',
        'tooltip' => 'Aktualisiert jedes installierte Projekt, für das es eine neuere kompatible Version gibt. Reine Client-Projekte und angepinnte Projekte werden übersprungen.',
        'heading' => 'Alle aktualisieren',
        'description' => 'Diese :count Projekte werden auf ihre neueste kompatible Version aktualisiert: :list. Reine Client-Projekte und Projekte, die nicht mehr auf Modrinth sind, werden übersprungen.',
        'skipped_pinned' => 'Übersprungen, angepinnt: :list.',
        'entry' => ':name (:old → :new)',
        'success_title' => 'Aktualisierungen installiert',
        'partial_title' => 'Einige Aktualisierungen sind fehlgeschlagen',
        'failed_title' => 'Aktualisierungen fehlgeschlagen',
        'updated' => 'Aktualisiert: :list.',
        'failed' => 'Nicht aktualisiert: :list. Die bisherige Version ist weiterhin installiert.',
        'nothing_title' => 'Nichts zu aktualisieren',
        'nothing_body' => 'Alle installierten Projekte sind bereits aktuell.',
    ],

    'dependencies' => [
        'installed' => 'Benötigte Abhängigkeiten ebenfalls installiert: :list',
        'problems_title' => 'Einige benötigte Abhängigkeiten fehlen',
        'failed' => 'Konnte nicht installiert werden: :list.',
        'manual' => 'Nicht auf Modrinth, bitte manuell installieren: :list.',
        'skipped' => 'Übersprungen, da nur für den Client: :list.',
        'unchecked' => 'Nicht installiert, da die Serverunterstützung nicht geprüft werden konnte, bei Bedarf manuell installieren: :list.',
        'error' => 'Die benötigten Abhängigkeiten konnten nicht geprüft werden.',
        'incompatible_heading' => 'Inkompatible Mod installiert',
        'incompatible_description' => 'Diese Version ist als inkompatibel mit :list markiert, das auf diesem Server installiert ist. Trotzdem installieren?',
    ],

    'datapacks' => [
        'update_heading' => 'Datenpaket aktualisieren',
        'uninstall_heading' => 'Datenpaket deinstallieren',
        'world_unknown' => 'Weltordner unbekannt',
        'world_unknown_body' => 'server.properties konnte nicht gelesen werden, daher ist der Weltordner für Datenpakete unbekannt. Es wurde nichts geändert; versuche es erneut, sobald der Server erreichbar ist.',
        'world_invalid_body' => 'Der level-name in server.properties ist kein Ordner innerhalb des Servers (zum Beispiel ein absoluter Pfad, "." oder "..", ein Backslash oder Leerzeichen am Rand), daher ist der Weltordner für Datenpakete unbekannt. Es wurde nichts geändert; korrigiere level-name und versuche es erneut.',
    ],
];
