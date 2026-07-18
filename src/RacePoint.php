<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Runtime\Contracts\CheckpointHandler;

final readonly class RacePoint implements CheckpointHandler
{
    public function __construct(private RaceContext $context) {}

    public function sync(string $name, ?int $timeoutMs = null): void
    {
        if (! $this->context->active()) {
            return;
        }

        $plan = $this->context->plan();
        $store = $this->context->store();
        $participantId = $this->context->participantId();

        if ($plan === null || $store === null || $participantId === null) {
            return;
        }

        if (! in_array($name, $plan->checkpoints, true)) {
            throw new InvalidRacePlan(
                "Checkpoint [{$name}] was reached but not registered with releaseWhenAllReach().",
            );
        }

        $store->reachCheckpoint($plan->runId, $participantId, $name);
        $store->waitForCheckpoint($plan->runId, $name, $timeoutMs ?? $plan->runTimeoutMs);
    }
}
