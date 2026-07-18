<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;

interface RequestExecutor
{
    public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult;
}
