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
        'rollup_retention_days' => 'Keep hourly history for',
        'rollup_retention_days_help' => 'Hourly averages and peaks behind the 7 and 30 day charts and the heatmap. Older hours are deleted once a day.',
        'minutes' => 'minutes',
        'days' => 'days',
        'saved' => 'Settings saved',
    ],

    'activity' => [
        'navigation' => 'Activity',
        'title' => 'Player activity',
        'none' => '—',
        'peak_today' => 'Peak today',
        'peak_week' => 'Peak this week',
        'peak_all_time' => 'All-time peak',
        'peak_all_time_help' => 'As far back as hourly history is kept',
        'average_7d' => '7-day average',
        'average_7d_help' => 'While online; offline time is not counted',
        'chart_heading' => 'Players online',
        'chart_gaps' => 'Gaps mean the server was offline, not answering or not polled.',
        'series_online' => 'Players online',
        'series_average' => 'Average players online',
        'range' => [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
        ],
        'heatmap_heading' => 'Busiest times',
        'heatmap_description' => 'Average players online by weekday and hour over the last :days days (:timezone). Empty cells: no data, or the server was offline.',
        'heatmap_cell' => ':time: :count players on average',
        'heatmap_empty_cell' => ':time: no data',
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

    'profile' => [
        'identity' => 'Identity',
        'uuid' => 'UUID',
        'uuid_unknown' => 'Not known yet',
        'previous_names' => 'Previous names',
        'none' => 'None',
        'activity' => 'Activity',
        'status' => 'Status',
        'last_seen' => 'Last seen :time',
        'total_playtime' => 'Total playtime',
        'week_playtime' => 'Last 7 days',
        'sessions' => 'Sessions',
        'started' => 'Joined',
        'ended' => 'Left',
        'duration' => 'Duration',
        'source' => 'Source',
        'no_sessions' => 'No sessions yet',
    ],

    'stats' => [
        'heading' => 'Statistics',
        'description' => 'From the world\'s stats file, refreshed every 10 minutes.',
        'play_time' => 'Play time',
        'deaths' => 'Deaths',
        'mob_kills' => 'Mob kills',
        'player_kills' => 'Player kills',
        'distance_walked' => 'Distance walked',
        'jumps' => 'Jumps',
        'none' => 'No stats yet. The server writes them when the player leaves or the world is saved.',
        'unavailable' => 'The stats file could not be read right now. Try again in a minute.',
        'no_permission' => 'Statistics are shown to users who can read the server\'s files.',
    ],

    'widget' => [
        'label' => 'Players online',
        'no_data' => 'No data yet',
    ],
];
