<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use RaceProof\Laravel\Contracts\WorkerControlClock;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerTransport;
use RaceProof\Laravel\Exceptions\RaceProofException;

/**
 * @internal
 */
final readonly class RemoteWorkerProcessFactory implements WorkerTransport
{
    public function __construct(
        private RemoteWorkerConfiguration $config,
        private WorkerControlPlane $control,
        private RemoteControlMessageCodec $codec,
        private WorkerControlClock $clock,
    ) {}

    public function create(string $runId, string $participantId): WorkerProcess
    {
        $agentId = $this->config->agentFor($participantId);

        if (! $this->control->agentAvailable($agentId)) {
            throw new RaceProofException("RaceProof remote worker agent [{$agentId}] is unavailable.");
        }

        return new RemoteWorkerProcess(
            $runId,
            $participantId,
            $agentId,
            $this->control,
            $this->codec,
            $this->config,
            $this->clock,
        );
    }

    public function driver(): string
    {
        return 'remote';
    }

    public function healthCheck(): void
    {
        $this->control->healthCheck();

        foreach ($this->config->agents as $agentId) {
            if (! $this->control->agentAvailable($agentId)) {
                throw new RaceProofException("RaceProof remote worker agent [{$agentId}] is unavailable.");
            }
        }
    }
}
