<?php

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'users'],
    ],
    'providers' => [
        'users' => ['driver' => 'database', 'table' => 'users'],
    ],
    'passwords' => [
        'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
    ],
    'password_timeout' => 10800,
    'jwt' => [
        'access_secret' => env('JWT_ACCESS_SECRET'),
        'access_expiry' => env('JWT_ACCESS_EXPIRY', '60m'),
        'refresh_secret' => env('JWT_REFRESH_SECRET'),
        'refresh_expiry' => env('JWT_REFRESH_EXPIRY', '7d'),
    ],
    'storage_signing_secret' => env('STORAGE_SIGNING_SECRET'),
    'cors_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
];
