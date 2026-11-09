<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',
    'minecraft_datapacks' => 'Minecraft Datapacks',

    'settings' => [
        'latest_minecraft_version' => 'Latest Minecraft Version',
        'settings_saved' => 'Settings saved',
    ],

    'page' => [
        'open_folder' => 'Open :folder folder',
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'installed' => 'Installed :type',
        'unknown' => 'Unknown',
        'view_all' => 'All',
        'view_installed' => 'Installed',
        'mod_unavailable' => 'This mod/plugin is no longer available on Modrinth',
    ],

    'client_only' => [
        'include' => 'Include client-only mods',
        'tooltip' => 'Turn on to also list mods that do nothing on a server (like Sodium or Iris). They carry a red Client-only badge; players add them to their own game.',
        'badge' => 'Client-only — does nothing on a server, safe to remove',
        'badge_tooltip' => 'Client-only: does nothing on a server; players add it to their own game if they want it',
        'search_badge' => 'Client-only',
        'search_badge_tooltip' => 'Client-only: does nothing on a server; players add it to their own game if they want it',
    ],

    'filters' => [
        'sort' => 'Sort by',
        'sort_default' => 'Default (relevance when searching, otherwise downloads)',
        'categories' => 'Categories',
    ],

    'sort' => [
        'relevance' => 'Relevance',
        'downloads' => 'Downloads',
        'follows' => 'Follows',
        'newest' => 'Newest',
        'updated' => 'Recently updated',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Downloads',
        'published' => 'Published',
        'changelog' => 'Changelog',
        'no_file_found' => 'No file found',
    ],

    'changelog' => [
        'none' => 'No changelog provided',
        'more' => ':count more on Modrinth',
    ],

    'actions' => [
        'install_latest' => 'Install latest version',
        'install' => 'Install',
        'installed' => 'Installed',
        'update' => 'Update',
        'uninstall' => 'Uninstall',
        'versions' => 'Version Selection',
    ],

    'modals' => [
        'update_heading' => 'Update Mod/Plugin',
        'update_description' => 'This will replace version :old_version with version :new_version. The old file will be deleted.',
        'uninstall_heading' => 'Uninstall Mod/Plugin',
        'uninstall_description' => 'Are you sure you want to uninstall :name? This will permanently delete the file from your server.',
    ],

    'sides' => [
        'players_need_it' => 'Players need it too',
        'players_need_it_tooltip' => 'Players need it too: install on the server and on every player\'s game',
    ],

    'compatibility' => [
        'action' => 'Check compatibility',
        'tooltip' => 'Check whether every installed project has a version for this Minecraft version and loader. Changes nothing.',
        'heading' => 'Compatibility check',
        'description' => 'Minecraft :version with :loader. This check does not install, update or remove anything.',
        'close' => 'Close',
        'none_installed' => 'Nothing is installed yet.',
        'all_compatible' => 'All :count installed projects have a version for this Minecraft version and loader.',
        'summary' => ':count of :total installed projects need attention:',
        'banner' => [
            'heading' => ':count installed project doesn\'t support Minecraft :version|:count installed projects don\'t support Minecraft :version',
            'description' => 'Installed versions without a build for this Minecraft version and :loader may fail to load. Check compatibility to see which ones and whether an update helps.',
            'pinned' => ':count of them is pinned and stays at its current version until it is unpinned.|:count of them are pinned and stay at their current versions until they are unpinned.',
        ],
        'pinned_label' => ':name (pinned)',
        'status' => [
            'needs_update' => 'Update needed',
            'incompatible' => 'Incompatible',
            'unknown' => 'Unknown',
        ],
        'details' => [
            'needs_update' => 'The installed version :installed is not built for this Minecraft version, but :newest is. Use the update button in the Installed tab.',
            'needs_update_pinned' => 'The installed version :installed is not built for this Minecraft version, but :newest is. This project is pinned: unpin it in the Installed tab to update it.',
            'incompatible' => 'No version of this project supports this Minecraft version and loader yet (installed: :installed).',
            'unknown' => 'Modrinth could not be reached for this project. Try again later.',
            'unknown_loader' => 'The server\'s loader is unknown, so compatibility cannot be checked.',
        ],
    ],

    'scan' => [
        'action' => 'Scan folder',
        'tooltip' => 'Find jars added by hand and look them up on Modrinth',
        'heading' => 'Scan folder',
        'description' => 'Looks for jars in :folder that are not in the installed list, checks each one on Modrinth by its file hash and adds the matches, so they can be updated and uninstalled here. Jars are only read: nothing is deleted, renamed or replaced. Large folders can take a moment.',
        'submit' => 'Scan',
        'finished' => 'Scan finished',
        'finished_with_problems' => 'Scan finished with problems',
        'nothing_new' => 'No new jars found.',
        'imported' => 'Imported :count: :list.',
        'not_on_modrinth' => 'Not on Modrinth (:count): :list.',
        'duplicates' => 'Left alone, the project is already installed from another file (:count): :list.',
        'skipped' => 'Skipped, too large or unreadable (:count): :list.',
        'error' => 'Modrinth could not be reached or the installed list could not be read or saved. Try again later.',
        'failed' => 'Scan failed',
        'failed_body' => 'The folder could not be read. Please try again or contact support if the issue persists.',
        'badge' => 'Not on Modrinth',
        'badge_tooltip' => 'This jar was added by hand and Modrinth does not know it, so it cannot be updated here.',
        'row_description' => 'Added by hand. Only uninstalling is possible here.',
    ],

    'notifications' => [
        'install_success' => 'Installation completed',
        'install_success_body' => 'Successfully installed :name version :version',
        'install_failed' => 'Installation failed',
        'install_failed_body' => 'An error occurred during installation. Please try again or contact support if the issue persists.',
        'update_success' => 'Update completed',
        'update_success_body' => 'Successfully updated to version :version',
        'update_failed' => 'Update failed',
        'update_failed_body' => 'An error occurred during the update. Please try again or contact support if the issue persists.',
        'uninstall_success' => 'Uninstall completed',
        'uninstall_success_body' => 'Successfully uninstalled :name',
        'uninstall_partial' => 'Uninstall incomplete',
        'uninstall_partial_body' => 'The file for :name was deleted, but it could not be removed from the installed list. It may still appear as installed.',
        'uninstall_failed' => 'Uninstall failed',
        'uninstall_failed_body' => 'An error occurred during uninstallation. Please try again or contact support if the issue persists.',
    ],

    'pin' => [
        'pin_tooltip' => 'Pin: keep this version, no updates are offered',
        'unpin_tooltip' => 'Unpin: offer updates again',
        'badge' => 'Pinned',
        'badge_tooltip' => 'Kept at this version: no update is offered and "Update all" skips it. Installing a specific version still works.',
        'pinned' => 'Pinned',
        'pinned_body' => ':name stays at its current version until you unpin it.',
        'unpinned' => 'Unpinned',
        'unpinned_body' => 'Updates are offered for :name again.',
        'failed' => 'Could not change the pin',
        'failed_body' => 'The installed list could not be read or saved, so nothing was changed for :name. Try again later.',
    ],

    'modpack' => [
        'badge' => 'From modpack :name',
        'badge_tooltip' => 'Installed with the modpack :name (by Modpacks Plus). You can update it on its own, but that may break the pack.',
        'update_warning' => 'This mod came with the modpack :name: updating it on its own may break the pack.',
        'update_all_warning' => 'These came with a modpack, and updating them on their own may break it: :list.',
        'update_all_entry' => ':name (:pack)',
        'install_heading' => 'Change a modpack mod?',
    ],

    'update_all' => [
        'action' => 'Update all (:count)',
        'tooltip' => 'Update every installed project that has a newer compatible version. Client-only and pinned projects are skipped.',
        'heading' => 'Update all',
        'description' => 'These :count projects will be updated to their newest compatible version: :list. Client-only projects and projects no longer on Modrinth are skipped.',
        'skipped_pinned' => 'Skipped, pinned: :list.',
        'entry' => ':name (:old → :new)',
        'success_title' => 'Updates installed',
        'partial_title' => 'Some updates failed',
        'failed_title' => 'Updates failed',
        'updated' => 'Updated: :list.',
        'failed' => 'Could not update: :list. Their current version is still installed.',
        'nothing_title' => 'Nothing to update',
        'nothing_body' => 'Every installed project is already up to date.',
    ],

    'dependencies' => [
        'installed' => 'Also installed required dependencies: :list',
        'problems_title' => 'Some required dependencies are missing',
        'failed' => 'Could not install: :list.',
        'manual' => 'Not on Modrinth, install manually: :list.',
        'skipped' => 'Skipped because they are client-only: :list.',
        'unchecked' => 'Not installed because they could not be checked for server support, install manually if needed: :list.',
        'error' => 'The required dependencies could not be checked.',
        'incompatible_heading' => 'Incompatible mod installed',
        'incompatible_description' => 'This version is marked as incompatible with :list, which is installed on this server. Install it anyway?',
    ],

    'datapacks' => [
        'update_heading' => 'Update Datapack',
        'uninstall_heading' => 'Uninstall Datapack',
        'world_unknown' => 'World folder unknown',
        'world_unknown_body' => 'server.properties could not be read, so the world folder for datapacks is unknown. Nothing was changed; try again once the server is reachable.',
        'world_invalid_body' => 'The level-name in server.properties is not a folder inside the server (for example an absolute path, "." or "..", a backslash or surrounding spaces), so the world folder for datapacks is unknown. Nothing was changed; fix level-name and try again.',
    ],
];
