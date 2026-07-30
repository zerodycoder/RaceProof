<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Remote\RemoteControlMessage;
use RaceProof\Laravel\Remote\RemoteWorkerState;

/**
 * @internal Authenticated remote worker controls are persisted behind this boundary.
 */
interface WorkerControlPlane
{
    public function healthCheck(): void;

    public function heartbeat(string $agentId): void;

    public function agentAvailable(string $agentId): bool;

    public function dispatch(RemoteControlMessage $message, string $envelope): void;

    public function next(string $agentId, string $action): ?string;

    public function claim(RemoteControlMessage $message): bool;

    public function markRunning(RemoteControlMessage $message): bool;

    public function finish(
        string $runId,
        string $participantId,
        int $exitCode,
        string $output,
        string $errorOutput,
        bool $stopped,
    ): void;

    public function state(string $runId, string $participantId): ?RemoteWorkerState;
}
