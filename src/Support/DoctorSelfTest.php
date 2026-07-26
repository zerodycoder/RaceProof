<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class DoctorSelfTest
{
    public function __construct(
        private Application $app,
        private Config $config,
    ) {}

    public function run(): void
    {
        $artisan = $this->app->basePath('artisan');

        if (! is_file($artisan)) {
            throw new RuntimeException('Laravel artisan was not found in the application base path.');
        }

        $timeoutMilliseconds = ConfigValue::integer(
            $this->config,
            'raceproof.doctor.self_test_timeout_ms',
            15_000,
        );

        if ($timeoutMilliseconds < 100 || $timeoutMilliseconds > 120_000) {
            throw new RuntimeException('Doctor self-test timeout must be from 100 through 120000 milliseconds.');
        }

        $outputBytes = ConfigValue::integer(
            $this->config,
            'raceproof.doctor.self_test_output_bytes',
            65_536,
        );

        if ($outputBytes < 1_024 || $outputBytes > 1_048_576) {
            throw new RuntimeException('Doctor self-test output limit must be from 1024 through 1048576 bytes.');
        }

        $process = new Process([
            PHP_BINARY,
            $artisan,
            'raceproof:doctor',
            '--json',
            '--no-interaction',
        ], $this->app->basePath(), timeout: $timeoutMilliseconds / 1_000);

        try {
            $exitCode = $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("Laravel child process exceeded {$timeoutMilliseconds} milliseconds.");
        } catch (Throwable) {
            throw new RuntimeException('Laravel child process could not be started.');
        }

        if ($exitCode !== 0) {
            throw new RuntimeException("Child Doctor exited with status {$exitCode}.");
        }

        $output = $process->getOutput();

        if (strlen($output) > $outputBytes) {
            throw new RuntimeException("Child Doctor output exceeded {$outputBytes} bytes.");
        }

        try {
            $payload = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Child Doctor returned invalid JSON.');
        }

        if (
            ! is_array($payload)
            || ($payload['schema_version'] ?? null) !== 1
            || ($payload['ok'] ?? null) !== true
        ) {
            throw new RuntimeException('Child Doctor did not report a successful schema-v1 result.');
        }
    }
}
