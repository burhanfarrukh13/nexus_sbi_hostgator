<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'private_root' => env('PRIVATE_STORAGE_PATH', dirname(base_path()).DIRECTORY_SEPARATOR.'private-storage'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],
    ],
    'links' => [],
];
