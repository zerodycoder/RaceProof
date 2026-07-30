<?php

declare(strict_types=1);

return [
    'default' => 'raceproof_database',

    'connections' => [
        'raceproof_database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION'),
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 180,
            'after_commit' => false,
        ],
    ],

    'failed' => [
        'driver' => 'null',
    ],
];
