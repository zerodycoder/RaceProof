<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

interface RaceClock
{
    public function nowNs(): int;

    public function sleepMilliseconds(int $milliseconds): void;
}
