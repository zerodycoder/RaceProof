<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Contracts\WorkerTransport;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RemoteWorkerProcessFactory;
use RaceProof\Laravel\Support\ConfigValue;

/**
 * @internal
 */
final class WorkerTransportResolver implements WorkerProcessFactory
{
    private ?WorkerTransport $resolved = null;

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
        private readonly CoordinatorStore $coordinator,
    ) {}

    public function create(string $runId, string $participantId): WorkerProcess
    {
        return $this->resolve()->create($runId, $participantId);
    }

    public function driver(): string
    {
        return $this->resolve()->driver();
    }

    public function healthCheck(): void
    {
        $this->resolve()->healthCheck();
    }

    private function resolve(): WorkerTransport
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $driver = ConfigValue::string($this->config, 'raceproof.worker_transport.driver');

        if ($driver === 'remote' && $this->coordinator->driver() !== 'redis') {
            throw new RaceProofException(
                'RaceProof remote worker transport requires the Redis coordinator.',
            );
        }

        $transport = match ($driver) {
            'local' => $this->container->make(SymfonyWorkerProcessFactory::class),
            'remote' => $this->container->make(RemoteWorkerProcessFactory::class),
            default => throw new RaceProofException(
                'RaceProof worker transport configuration is unsupported.',
            ),
        };

        if (! $transport instanceof WorkerTransport) {
            throw new RaceProofException('RaceProof worker transport resolved an invalid implementation.');
        }

        return $this->resolved = $transport;
    }
}
