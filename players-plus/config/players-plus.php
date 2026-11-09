<?php

return [
    // Turn polling off without uninstalling the plugin; stored samples stay readable.
    'enabled' => env('PLAYERS_PLUS_ENABLED', true),

    // Minutes between polls, 1 to 5.
    'poll_interval' => env('PLAYERS_PLUS_POLL_INTERVAL', 2),

    // Days raw samples are kept before the daily prune deletes them.
    'retention_days' => env('PLAYERS_PLUS_RETENTION_DAYS', 30),

    // Seconds each network step (connect, read) may take when querying a game server.
    'timeout' => env('PLAYERS_PLUS_TIMEOUT', 3),
];
