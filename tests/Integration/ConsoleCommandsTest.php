<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Runtime\Checkpoint;
use RuntimeException;

final class ConsoleCommandsTest extends TestCase
{
    private string $coordinatorPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinatorPath = dirname(__DIR__, 2).'/build/console-command-tests/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.coordinator.path', $this->coordinatorPath);
        $this->app->forgetInstance(FileCoordinatorStore::class);
        $this->app->forgetInstance(CoordinatorResolver::class);
        $this->app->forgetInstance(CoordinatorStore::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->coordinatorPath);

        parent::tearDown();
    }

    public function test_doctor_reports_a_safe_test_environment(): void
    {
        $this->artisan('raceproof:doctor')
            ->expectsOutputToContain('Environment safety')
            ->expectsOutputToContain('Database safety')
            ->expectsOutputToContain('Coordinator writable')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_doctor_returns_failure_when_a_safety_check_fails(): void
    {
        $this->app['config']->set('raceproof.enabled', false);

        $this->artisan('raceproof:doctor')
            ->expectsOutputToContain('RaceProof is disabled')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_doctor_emits_versioned_json_without_raw_environment_values(): void
    {
        self::assertSame(Command::SUCCESS, Artisan::call('raceproof:doctor', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['schema_version']);
        self::assertTrue($payload['ok']);
        self::assertCount(5, $payload['checks']);
        self::assertSame(
            [
                'environment-safety',
                'database-safety',
                'proc-open',
                'php-binary',
                'coordinator-writable',
            ],
            array_column($payload['checks'], 'id'),
        );
        self::assertStringNotContainsString('APP_ENV', $output);
        self::assertStringNotContainsString('DB_PASSWORD', $output);
    }

    public function test_doctor_json_keeps_failed_checks_structured_and_actionable(): void
    {
        $this->app['config']->set('raceproof.enabled', false);

        self::assertSame(Command::FAILURE, Artisan::call('raceproof:doctor', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['ok']);
        self::assertSame('environment-safety', $payload['checks'][0]['id']);
        self::assertSame('fail', $payload['checks'][0]['status']);
        self::assertStringContainsString('RaceProof is disabled', $payload['checks'][0]['message']);
    }

    public function test_doctor_rejects_an_unknown_coordinator_without_exposing_configuration(): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';
        $this->app['config']->set('raceproof.coordinator.driver', $secret);
        $this->app->forgetInstance(CoordinatorResolver::class);
        $this->app->forgetInstance(CoordinatorStore::class);

        self::assertSame(Command::FAILURE, Artisan::call('raceproof:doctor', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $coordinator = $payload['checks'][4];

        self::assertSame('coordinator-writable', $coordinator['id']);
        self::assertSame('fail', $coordinator['status']);
        self::assertStringContainsString('configuration is unsupported', $coordinator['message']);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringNotContainsString('super-secret', $output);
    }

    public function test_doctor_self_test_uses_a_separate_bounded_child_process(): void
    {
        $basePath = $this->app->basePath();
        $directory = dirname(__DIR__, 2).'/build/doctor-command-child/'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/artisan', <<<'PHP'
            <?php
            echo json_encode(['schema_version' => 1, 'ok' => true, 'checks' => []]);
            PHP, LOCK_EX);
        $this->app->setBasePath($directory);

        try {
            self::assertSame(Command::SUCCESS, Artisan::call('raceproof:doctor', [
                '--json' => true,
                '--self-test' => true,
            ]));
            $payload = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

            self::assertTrue($payload['ok']);
            self::assertSame('laravel-child-process', $payload['checks'][5]['id']);
            self::assertSame('pass', $payload['checks'][5]['status']);
        } finally {
            $this->app->setBasePath($basePath);
            $this->removeDirectory($directory);
        }
    }

    public function test_installer_is_idempotent_and_never_mutates_environment_files(): void
    {
        $configPath = $this->app->configPath();
        $directory = dirname(__DIR__, 2).'/build/install-command/'.bin2hex(random_bytes(8));
        $environment = $directory.'/.env.testing';
        mkdir($directory, 0700, true);
        file_put_contents($environment, "EXISTING_VALUE=preserved\n", LOCK_EX);
        $this->app->useConfigPath($directory);

        try {
            $this->artisan('raceproof:install')
                ->expectsOutputToContain('Published RaceProof configuration')
                ->expectsOutputToContain('RaceProof did not modify any environment file.')
                ->assertExitCode(Command::SUCCESS);

            $target = $directory.'/raceproof.php';
            self::assertFileExists($target);
            self::assertSame("EXISTING_VALUE=preserved\n", file_get_contents($environment));

            file_put_contents($target, "<?php return ['preserved' => true];\n", LOCK_EX);
            $this->artisan('raceproof:install')
                ->expectsOutputToContain('left unchanged')
                ->assertExitCode(Command::SUCCESS);
            self::assertStringContainsString('preserved', (string) file_get_contents($target));

            $this->artisan('raceproof:install', ['--force' => true])
                ->expectsOutputToContain('Published RaceProof configuration')
                ->assertExitCode(Command::SUCCESS);
            self::assertStringContainsString("'self_test_timeout_ms' => 15_000", (string) file_get_contents($target));
            self::assertStringContainsString("'self_test_output_bytes' => 65_536", (string) file_get_contents($target));
            self::assertStringContainsString(
                "'driver' => env('RACEPROOF_COORDINATOR_DRIVER', 'file')",
                (string) file_get_contents($target),
            );
            self::assertSame("EXISTING_VALUE=preserved\n", file_get_contents($environment));
        } finally {
            $this->app->useConfigPath($configPath);
            $this->removeDirectory($directory);
        }
    }

    public function test_clean_removes_only_valid_run_directories(): void
    {
        $valid = str_repeat('a', 32);
        $invalid = 'not-a-race-run';
        mkdir($this->coordinatorPath.'/'.$valid, 0700, true);
        mkdir($this->coordinatorPath.'/'.$invalid, 0700, true);

        $this->artisan('raceproof:clean')
            ->expectsOutputToContain('Removed 1 RaceProof run(s).')
            ->assertExitCode(Command::SUCCESS);

        self::assertDirectoryDoesNotExist($this->coordinatorPath.'/'.$valid);
        self::assertDirectoryExists($this->coordinatorPath.'/'.$invalid);
    }

    public function test_worker_rejects_missing_string_options(): void
    {
        $this->artisan('raceproof:worker')->assertExitCode(Command::INVALID);
    }

    public function test_worker_rejects_a_parent_driver_mismatch_before_reading_the_plan(): void
    {
        $this->artisan('raceproof:worker', [
            '--run' => str_repeat('a', 32),
            '--participant' => 'p1',
            '--driver' => 'redis',
        ])
            ->expectsOutputToContain('does not match the parent process')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_worker_executes_and_stores_a_result(): void
    {
        Route::post('/raceproof/worker', fn () => response('worker-ok', 202));
        $plan = $this->plan(str_repeat('b', 32));
        $store = new FileCoordinatorStore($this->coordinatorPath);
        $store->createRun($plan);
        $store->releaseStart($plan->runId);

        $this->artisan('raceproof:worker', [
            '--run' => $plan->runId,
            '--participant' => 'p1',
            '--driver' => 'file',
        ])->assertExitCode(Command::SUCCESS);

        $results = $store->results($plan->runId);

        self::assertCount(1, $results);
        self::assertSame(202, $results[0]->status);
        self::assertSame('worker-ok', $results[0]->body);
    }

    public function test_worker_records_an_executor_failure_after_loading_the_plan(): void
    {
        $plan = $this->plan(str_repeat('c', 32));
        $store = new FileCoordinatorStore($this->coordinatorPath);
        $store->createRun($plan);
        $store->releaseStart($plan->runId);
        $this->app->bind(RequestExecutor::class, fn (): RequestExecutor => new class implements RequestExecutor
        {
            public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult
            {
                throw new RuntimeException('executor exploded password=hunter2');
            }
        });

        $this->artisan('raceproof:worker', [
            '--run' => $plan->runId,
            '--participant' => 'p1',
            '--driver' => 'file',
        ])->assertExitCode(Command::FAILURE);

        $results = $store->results($plan->runId);

        self::assertCount(1, $results);
        self::assertStringContainsString('RuntimeException: executor exploded', (string) $results[0]->workerError);
        self::assertStringContainsString('password=[REDACTED]', (string) $results[0]->workerError);
        self::assertStringNotContainsString('hunter2', (string) $results[0]->workerError);
    }

    public function test_worker_records_a_redacted_bootstrap_failure_and_deactivates_runtime(): void
    {
        $plan = $this->plan(
            str_repeat('d', 32),
            new BootstrapSpec(FailingParticipantBootstrap::class, ['mode' => 'failure-test']),
        );
        $store = new FileCoordinatorStore($this->coordinatorPath);
        $store->createRun($plan);

        $this->artisan('raceproof:worker', [
            '--run' => $plan->runId,
            '--participant' => 'p1',
            '--driver' => 'file',
        ])->assertExitCode(Command::FAILURE);

        $results = $store->results($plan->runId);
        $types = array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $store->timeline($plan->runId)->events,
        );

        self::assertCount(1, $results);
        self::assertStringContainsString('token=[REDACTED]', (string) $results[0]->workerError);
        self::assertStringNotContainsString('bootstrap-secret', (string) $results[0]->workerError);
        self::assertContains('participant.bootstrap_started', $types);
        self::assertContains('participant.bootstrap_failed', $types);
        self::assertFalse(Checkpoint::active());
    }

    private function plan(string $runId, ?BootstrapSpec $bootstrap = null): RacePlan
    {
        return new RacePlan(
            runId: $runId,
            participants: 2,
            request: new RequestSpec('POST', '/raceproof/worker'),
            spawnTimeoutMs: 1_000,
            runTimeoutMs: 1_000,
            pollIntervalMs: 1,
            bootstrap: $bootstrap,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}

final class FailingParticipantBootstrap implements ParticipantBootstrap
{
    public function bootstrap(ParticipantContext $context, array $configuration): void
    {
        throw new RuntimeException('bootstrap failed token=bootstrap-secret');
    }
}
