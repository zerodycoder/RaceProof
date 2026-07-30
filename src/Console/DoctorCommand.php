<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Execution\WorkerTransportResolver;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\DoctorSelfTest;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'raceproof:doctor
        {--json : Emit a versioned machine-readable result}
        {--self-test : Boot Doctor through a separate Laravel CLI process}';

    protected $description = 'Check whether this application can run RaceProof safely';

    public function __construct(
        private readonly EnvironmentGuard $environment,
        private readonly DatabaseSafety $database,
        private readonly CoordinatorResolver $coordinator,
        private readonly Container $container,
        private readonly DoctorSelfTest $selfTest,
        private readonly SensitiveDataRedactor $redactor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var array<string, array{id: string, run: Closure(): void}> $checks */
        $checks = [
            'Environment safety' => [
                'id' => 'environment-safety',
                'run' => fn () => $this->environment->ensureEnabled(),
            ],
            'Database safety' => [
                'id' => 'database-safety',
                'run' => fn () => $this->database->validate(),
            ],
            'proc_open available' => [
                'id' => 'proc-open',
                'run' => function (): void {
                    if (! function_exists('proc_open')) {
                        throw new \RuntimeException('proc_open is disabled.');
                    }
                },
            ],
            'PHP binary' => [
                'id' => 'php-binary',
                'run' => function (): void {
                    if (! is_file(PHP_BINARY)) {
                        throw new \RuntimeException('PHP_BINARY is not executable.');
                    }
                },
            ],
            'Coordinator writable' => [
                'id' => 'coordinator-writable',
                'run' => fn () => $this->coordinator->resolve()->healthCheck(),
            ],
            'Worker transport' => [
                'id' => 'worker-transport',
                'run' => function (): void {
                    $transport = $this->container->make(WorkerTransportResolver::class);

                    if (! $transport instanceof WorkerTransportResolver) {
                        throw new \RuntimeException('RaceProof resolved an invalid worker transport.');
                    }

                    $transport->healthCheck();
                },
            ],
        ];

        if ((bool) $this->option('self-test')) {
            $checks['Laravel child process'] = [
                'id' => 'laravel-child-process',
                'run' => fn () => $this->selfTest->run(),
            ];
        }

        $failed = false;
        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $check['run']();
                $results[] = [
                    'id' => $check['id'],
                    'label' => $name,
                    'status' => 'pass',
                    'message' => null,
                ];

                if (! (bool) $this->option('json')) {
                    $this->components->info($name);
                }
            } catch (Throwable $exception) {
                $failed = true;
                $message = $this->redactor->diagnostic($exception->getMessage());
                $results[] = [
                    'id' => $check['id'],
                    'label' => $name,
                    'status' => 'fail',
                    'message' => $message,
                ];

                if (! (bool) $this->option('json')) {
                    $this->components->error($name.': '.$message);
                }
            }
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => 1,
                'ok' => ! $failed,
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
