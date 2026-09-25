<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => (string) config('whoiswho.name', 'WhoIsWho SK'),
    'api' => '/api/v1/health',
]));
