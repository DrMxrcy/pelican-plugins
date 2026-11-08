<?php

return [
    // Hide projects Modrinth marks as client-only (server_side: unsupported) from the "All" tab.
    'hide_client_only' => env('MODRINTH_PLUS_HIDE_CLIENT_ONLY', true),
];
