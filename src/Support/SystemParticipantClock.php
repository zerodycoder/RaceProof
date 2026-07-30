<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Contracts\ParticipantClock;

/**
 * @internal
 */
final class SystemParticipantClock implements ParticipantClock
{
    public function nowNs(): int
    {
        return Clock::nowNs();
    }
}
