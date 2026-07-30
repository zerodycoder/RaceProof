<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Foundation\Application;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Exceptions\RaceProofException;
use Symfony\Component\Process\Process;

final readonly class SymfonyWorkerProcessFactory implements WorkerProcessFactory
{
    public function __construct(
        private Application $app,
        private CoordinatorStore $store,
    ) {}

    public function create(string $runId, string $participantId): WorkerProcess
    {
        $artisan = $this->app->basePath('artisan');

        if (! is_file($artisan)) {
            throw new RaceProofException("Laravel artisan file was not found at [{$artisan}].");
        }

        return new SymfonyWorkerProcess(new Process([
            PHP_BINARY,
            $artisan,
            'raceproof:worker',
            '--run='.$runId,
            '--participant='.$participantId,
            '--driver='.$this->store->driver(),
            '--no-interaction',
        ], $this->app->basePath(), timeout: null));
    }
}
