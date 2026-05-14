<?php

return [
    'enabled' => env('LOCKS_ENABLED', true),
    'ttl' => env('LOCK_TTL_SECONDS', 120),
    'cache_prefix' => env('LOCK_CACHE_PREFIX', 'lock.'),
    'ws_server_url' => env('WS_SERVER_URL', 'http://127.0.0.1:6001'),
    'resource_prefix' => env('LOCK_RESOURCE_PREFIX', ''),
];
