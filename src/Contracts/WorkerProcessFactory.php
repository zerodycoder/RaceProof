<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

interface WorkerProcessFactory
{
    public function create(string $runId, string $participantId): WorkerProcess;
}
