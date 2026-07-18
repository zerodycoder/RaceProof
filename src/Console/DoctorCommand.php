<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'raceproof:doctor';

    protected $description = 'Check whether this application can run RaceProof safely';

    public function __construct(
        private readonly EnvironmentGuard $environment,
        private readonly DatabaseSafety $database,
        private readonly FileCoordinatorStore $store,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = [
            'Environment safety' => fn () => $this->environment->ensureEnabled(),
            'Database safety' => fn () => $this->database->validate(),
            'proc_open available' => function (): void {
                if (! function_exists('proc_open')) {
                    throw new \RuntimeException('proc_open is disabled.');
                }
            },
            'PHP binary' => function (): void {
                if (! is_file(PHP_BINARY)) {
                    throw new \RuntimeException('PHP_BINARY is not executable.');
                }
            },
            'Coordinator writable' => function (): void {
                $path = $this->store->basePath();
                if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
                    throw new \RuntimeException("Cannot create [{$path}].");
                }
                if (! is_writable($path)) {
                    throw new \RuntimeException("Directory [{$path}] is not writable.");
                }
            },
        ];

        $failed = false;

        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->components->info($name);
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error($name.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
