<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

/**
 * @internal Participant timestamps need one comparable source per transport.
 */
interface ParticipantClock
{
    public function nowNs(): int;
}
