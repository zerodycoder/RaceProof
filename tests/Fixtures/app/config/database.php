<?php

declare(strict_types=1);

return [
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => __DIR__.'/../storage/database.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ],
        'queue_sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => __DIR__.'/../storage/queue.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
