<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Contracts\WorkerControlClock;

/**
 * @internal
 */
final class SystemWorkerControlClock implements WorkerControlClock
{
    public function nowMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1_000);
    }

    public function monotonicMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1_000);
    }
}
