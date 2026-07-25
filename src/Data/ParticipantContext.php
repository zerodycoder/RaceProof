<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

final readonly class ParticipantContext
{
    /** @internal RaceProof creates this value before invoking a participant bootstrap. */
    public function __construct(
        public string $runId,
        public string $participantId,
    ) {}
}
