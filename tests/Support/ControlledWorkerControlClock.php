<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Support;

use RaceProof\Laravel\Contracts\WorkerControlClock;

final class ControlledWorkerControlClock implements WorkerControlClock
{
    /** @var list<int> */
    public array $sleeps = [];

    public int $monotonicMs;

    public function __construct(
        public int $nowMs = 1_700_000_000_000,
        ?int $monotonicMs = null,
    ) {
        $this->monotonicMs = $monotonicMs ?? $nowMs;
    }

    public function nowMilliseconds(): int
    {
        return $this->nowMs;
    }

    public function monotonicMilliseconds(): int
    {
        return $this->monotonicMs;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->nowMs += $milliseconds;
        $this->monotonicMs += $milliseconds;
    }
}
