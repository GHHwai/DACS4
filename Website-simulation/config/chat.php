<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Real-time gateway (raw TCP + WebSocket on the same port)
    |--------------------------------------------------------------------------
    | Started with `php artisan chat:serve`. Browsers connect over WebSocket,
    | CLI / custom clients (netcat, sockets) connect over plain TCP using
    | newline-delimited JSON. See docs/PROTOCOL.md.
    */
    'host' => env('CHAT_TCP_HOST', '0.0.0.0'),
    'port' => (int) env('CHAT_TCP_PORT', 9501),

    // Origins allowed to open a browser WebSocket. Non-browser clients send no Origin.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CHAT_ALLOWED_ORIGINS',
        'http://localhost:8000,http://127.0.0.1:8000'
    ))))),

    'max_connections' => 500,
    'max_line_bytes' => 65536,       // largest single TCP line / WebSocket frame accepted
    'auth_timeout' => 10,            // seconds an unauthenticated socket may stay open
    'idle_timeout' => 120,           // seconds without any traffic before we drop a socket

    // Per-connection flood protection (commands per window).
    'rate_limit' => ['max' => 30, 'per_seconds' => 10],

    // Short-lived credential handed to the browser / CLI for the socket handshake.
    'token_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | Messages & uploads
    |--------------------------------------------------------------------------
    */
    'max_message_length' => 4000,
    'history_page_size' => 40,
    'group_max_members' => 100,

    'max_upload_kb' => 20480,        // 20 MB per file
    'max_files_per_message' => 5,
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'msi', 'bat', 'cmd', 'com',
        'scr', 'sh', 'bash', 'vbs', 'ps1', 'jar', 'dll', 'apk',
    ],
    'image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],

    /*
    |--------------------------------------------------------------------------
    | Spam / scam detection (future AI module plugs in here)
    |--------------------------------------------------------------------------
    | Bind a real implementation of App\Contracts\SpamDetector in
    | AppServiceProvider and flip CHAT_SPAM_ENABLED=true.
    */
    'spam' => [
        'enabled' => (bool) env('CHAT_SPAM_ENABLED', false),
    ],
];
