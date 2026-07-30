<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Results\RaceResult;

/**
 * @internal Orchestration remains replaceable only for deterministic fault tests.
 */
interface RaceRunner
{
    public function run(RacePlan $plan): RaceResult;
}
