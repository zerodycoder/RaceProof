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

    'doctor' => [
        'self_test_timeout_ms' => 15_000,
        'self_test_output_bytes' => 65_536,
    ],

    'capture' => [
        'response_body_bytes' => 16_384,
        'diagnostic_text_bytes' => 4_096,
        'worker_output_bytes' => 4_096,
        'headers' => ['content-type', 'location', 'x-request-id'],
        'redact_headers' => ['authorization', 'cookie', 'set-cookie'],
        'redact_keys' => ['password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token', 'client_secret', 'api_key', 'api-key'],
    ],

    'reporting' => [
        'human_output_bytes' => 16_384,
        'diagnostic_text_bytes' => 4_096,
        'response_body_bytes' => 4_096,
        'header_limit' => 32,
        'timeline_event_limit' => 500,
        'timeline_event_data_limit' => 16,
        'timeline_warning_limit' => 100,
    ],

    'studio' => [
        'enabled' => (bool) env('RACEPROOF_STUDIO_ENABLED', false),
        'path' => storage_path('framework/raceproof-studio'),
        'route_prefix' => 'raceproof',
        'allowed_ips' => ['127.0.0.1', '::1'],
        'max_reports' => 50,
        'max_report_bytes' => 1_048_576,
    ],

    'scaffolding' => [
        'test_path' => base_path('tests/Feature'),
    ],
];
