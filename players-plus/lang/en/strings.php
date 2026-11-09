<?php

return [
    'navigation' => 'Players',

    'settings' => [
        'enabled' => 'Poll Minecraft servers',
        'enabled_help' => 'Turn off to stop polling. Stored data stays visible.',
        'poll_interval' => 'Poll interval',
        'poll_interval_help' => 'How often every running Minecraft server is asked who is online (1 to 5 minutes).',
        'retention_days' => 'Keep samples for',
        'retention_days_help' => 'Older samples are deleted once a day.',
        'minutes' => 'minutes',
        'days' => 'days',
        'saved' => 'Settings saved',
    ],

    'status' => [
        'online' => 'Online',
        'offline' => 'Offline',
        'unreachable' => 'No answer',
    ],

    'source' => [
        'poll' => 'Poll',
        'log' => 'Log',
    ],

    'page' => [
        'title' => 'Players',
        'online_now' => 'Online now',
        'count' => ':online / :max',
        'updated' => 'Updated :time',
        'partial' => 'Server List Ping lists at most :limit players. Set enable-query=true in server.properties to see everyone.',
        'name' => 'Name',
        'empty' => 'Nobody is online',
        'no_data_title' => 'No player data yet',
        'no_data_body' => 'The server is checked every :minutes minute(s). Players show up here after the first check.',
        'offline_title' => 'The server is offline',
        'offline_body' => 'Last checked :time. Players show up here once the server is running.',
        'unreachable_title' => 'The server did not answer',
        'unreachable_body' => 'Last checked :time. The server is running but did not answer. Check that the panel can reach its game port.',
        'stale_title' => 'Player data is out of date',
        'stale_body' => 'The last check was :time. Check that the panel\'s queue worker and scheduler are running.',
    ],

    'tabs' => [
        'online' => 'Online',
        'all' => 'All players',
    ],

    'all' => [
        'heading' => 'All players',
        'last_seen' => 'Last seen',
        'online' => 'Online',
        'playtime' => 'Playtime',
        'sessions' => 'Sessions',
        'first_seen' => 'First seen',
        'empty' => 'No players seen yet',
        'partial' => 'Partial list: Server List Ping shows at most 12 players. Set enable-query=true in server.properties for full tracking.',
    ],

    'widget' => [
        'label' => 'Players online',
        'no_data' => 'No data yet',
    ],
];
