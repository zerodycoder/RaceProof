<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('consumer.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5_000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ],
        'queue_sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('queue.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10_000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ],
    ],
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'prefix' => '',
        ],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
            'read_timeout' => 2,
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
