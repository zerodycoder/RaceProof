<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;

/**
 * @internal Participant execution is selected from the validated run plan.
 */
interface ParticipantExecutor
{
    public function prepare(RacePlan $plan, ParticipantContext $context): void;

    public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult;
}
