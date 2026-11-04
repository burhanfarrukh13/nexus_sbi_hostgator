<?php

return [
    'driver' => 'bcrypt',
    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => env('BCRYPT_LIMIT', null),
    ],
    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
