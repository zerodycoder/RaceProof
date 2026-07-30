<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Container\Container;
use RaceProof\Laravel\Contracts\ParticipantExecutor;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Queue\QueueJobExecutor;

/**
 * @internal Selects the executor from the plan rather than ambient configuration.
 */
final readonly class ParticipantExecutorResolver implements ParticipantExecutor
{
    public function __construct(
        private RequestExecutor $requests,
        private Container $container,
    ) {}

    public function prepare(RacePlan $plan, ParticipantContext $context): void
    {
        if ($plan->queue === null) {
            $this->requests->prepare($plan, $context);

            return;
        }

        $this->queues()->prepare($plan, $context);
    }

    public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult
    {
        return $plan->queue === null
            ? $this->requests->execute($plan, $context)
            : $this->queues()->execute($plan, $context);
    }

    private function queues(): QueueJobExecutor
    {
        return $this->container->make(QueueJobExecutor::class);
    }
}
