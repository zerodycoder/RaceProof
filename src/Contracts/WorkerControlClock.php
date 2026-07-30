<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

/**
 * @internal Remote control uses wall-clock time because messages cross hosts.
 */
interface WorkerControlClock
{
    public function nowMilliseconds(): int;

    public function monotonicMilliseconds(): int;

    public function sleepMilliseconds(int $milliseconds): void;
}
