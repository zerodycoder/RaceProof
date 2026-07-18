<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

final readonly class ParticipantContext
{
    public function __construct(
        public string $runId,
        public string $participantId,
    ) {}
}
