<?php

/*
 | Without this file Laravel falls back to its framework default, which answers
 | "Access-Control-Allow-Origin: *" for every /api request. Only the origins listed in
 | CORS_ALLOWED_ORIGINS (comma separated, exact https origins) may call the API from a
 | browser. App\Http\Middleware\CorsAllowlist applies the same list.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],
    'exposed_headers' => ['Retry-After'],
    'max_age' => 600,
    // The app sends a bearer token in a header, never cookies.
    'supports_credentials' => false,
];
