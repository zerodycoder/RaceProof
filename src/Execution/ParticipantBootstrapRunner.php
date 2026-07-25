<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Foundation\Application;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final readonly class ParticipantBootstrapRunner
{
    public function __construct(private Application $app) {}

    public function run(RacePlan $plan, ParticipantContext $context): void
    {
        $bootstrapSpec = $plan->bootstrapFor($context->participantId);

        if ($bootstrapSpec === null) {
            return;
        }

        $bootstrap = $this->app->make($bootstrapSpec->class);

        if (! $bootstrap instanceof ParticipantBootstrap) {
            throw new InvalidRacePlan("Resolved participant bootstrap [{$bootstrapSpec->class}] is invalid.");
        }

        $bootstrap->bootstrap($context, $bootstrapSpec->configuration);
    }
}
