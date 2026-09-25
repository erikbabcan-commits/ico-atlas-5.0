<?php

return [
    'name' => 'WhoIsWho SK',

    /*
    |--------------------------------------------------------------------------
    | Cache / freshness
    |--------------------------------------------------------------------------
    | Koľko hodín považujeme persistovaný profil za čerstvý.
    */
    'cache_ttl_hours' => env('WHOISWHO_CACHE_TTL_HOURS', 24),

    'http_timeout' => env('WHOISWHO_HTTP_TIMEOUT', 12),

    /*
    |--------------------------------------------------------------------------
    | Zdroje dát (priorita: RPO → RÚZ → RPVS → ORSR scrape fallback)
    |--------------------------------------------------------------------------
    */
    'rpo' => [
        'base_url' => env('WHOISWHO_RPO_BASE_URL', 'https://api.statistics.sk/rpo/v1'),
        'enabled' => env('WHOISWHO_RPO_ENABLED', true),
    ],

    'ruz' => [
        'base_url' => env('WHOISWHO_RUZ_BASE_URL', 'https://www.registeruz.sk/cruz-public/api'),
        'enabled' => env('WHOISWHO_RUZ_ENABLED', true),
    ],

    'rpvs' => [
        'base_url' => env('WHOISWHO_RPVS_BASE_URL', 'https://rpvs.gov.sk/opendatav2'),
        'enabled' => env('WHOISWHO_RPVS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy ORSR HTML scrape — P3 fallback, NIKDY default path.
    | Backend only, cache TTL >= 24h, <= 1 req/s, backoff + circuit breaker.
    |--------------------------------------------------------------------------
    */
    'orsr_scrape' => [
        'enabled' => env('WHOISWHO_ORSR_SCRAPE_ENABLED', false),
        'base_url' => env('WHOISWHO_ORSR_BASE_URL', 'https://www.orsr.sk'),
        'search_url' => env('WHOISWHO_ORSR_SEARCH_URL', 'https://www.orsr.sk/hladaj_ico.asp'),
        'cache_ttl_hours' => env('WHOISWHO_ORSR_TTL_HOURS', 24),
        'min_interval_seconds' => env('WHOISWHO_ORSR_MIN_INTERVAL', 1),
        'max_retries' => env('WHOISWHO_ORSR_MAX_RETRIES', 3),
        'circuit_failure_threshold' => env('WHOISWHO_ORSR_CB_THRESHOLD', 5),
        'circuit_reset_seconds' => env('WHOISWHO_ORSR_CB_RESET', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth — Bearer service API key
    |--------------------------------------------------------------------------
    */
    'api_key' => env('WHOISWHO_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Disclaimer — súčasť každého JSON výstupu
    |--------------------------------------------------------------------------
    */
    'disclaimer' => env(
        'WHOISWHO_DISCLAIMER',
        'Toto nie je úradný výpis. Údaje sú informatívne, získané z verejných registrov SR ' .
        '(RPO, RÚZ, RPVS, ORSR) k dátumu uvedenému v retrieved_at. Zohľadnite zákon č. 29/2026 Z. z. ' .
        'o obchodnom registri a správnosť zdrojových registrov.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Risk thresholds (deterministické, bez LLM)
    |--------------------------------------------------------------------------
    */
    'risk' => [
        'multi_board_min_companies' => 2,
        'insolvency_keywords' => ['konkurz', 'likvid', 'reštrukturaliz', 'núdzov'],
        'weights' => [
            'MULTI_BOARD' => 0.35,
            'INSOLVENT_LINKS' => 0.40,
            'SHARED_SEAT' => 0.15,
            'RPVS_UBO' => 0.20,
        ],
    ],

    'graph' => [
        'max_depth' => 2,
        'max_nodes' => 200,
    ],
];
