<?php

declare(strict_types=1);

return [
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'raceproof_database' => [
            'driver' => 'database',
            'connection' => 'queue_sqlite',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 30,
            'after_commit' => false,
        ],

        'raceproof_redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 30,
            'block_for' => null,
            'after_commit' => false,
        ],
    ],

    'failed' => [
        'driver' => 'null',
    ],
];
