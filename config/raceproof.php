<?php

declare(strict_types=1);

return [
    'enabled' => env('RACEPROOF_ENABLED', env('APP_ENV') === 'testing'),

    'runner' => [
        'participants' => 5,
        'spawn_timeout_ms' => 10_000,
        'run_timeout_ms' => 15_000,
        'poll_interval_ms' => 5,
        'cleanup_successful_runs' => true,
    ],

    'coordinator' => [
        'path' => storage_path('framework/raceproof'),
    ],

    'database' => [
        'reject_open_transactions' => true,
        'reject_in_memory_sqlite' => true,
        'allowed_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RACEPROOF_ALLOWED_DATABASES', ''))
        ))),
        'require_allowlist' => (bool) env('RACEPROOF_REQUIRE_DATABASE_ALLOWLIST', false),
    ],

    'capture' => [
        'response_body_bytes' => 16_384,
        'worker_output_bytes' => 4_096,
        'headers' => ['content-type', 'location', 'x-request-id'],
        'redact_headers' => ['authorization', 'cookie', 'set-cookie'],
    ],
];
