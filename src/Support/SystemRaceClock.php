<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Contracts\RaceClock;

final class SystemRaceClock implements RaceClock
{
    public function nowNs(): int
    {
        return Clock::nowNs();
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1_000);
    }
}
