<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Data\ParticipantContext;

interface ParticipantBootstrap
{
    /** @param array<string, mixed> $configuration */
    public function bootstrap(ParticipantContext $context, array $configuration): void;
}
