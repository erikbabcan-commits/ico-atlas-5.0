<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => array_map('trim', explode(',', (string) env('WHOISWHO_CORS_METHODS', 'GET,POST,OPTIONS'))),

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHOISWHO_CORS_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
