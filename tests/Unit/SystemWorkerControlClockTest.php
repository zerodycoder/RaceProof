<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Support\SystemWorkerControlClock;

final class SystemWorkerControlClockTest extends TestCase
{
    public function test_it_exposes_wall_and_monotonic_time_and_sleeps_in_milliseconds(): void
    {
        $clock = new SystemWorkerControlClock;
        $wallBefore = $clock->nowMilliseconds();
        $monotonicBefore = $clock->monotonicMilliseconds();

        $clock->sleepMilliseconds(1);

        self::assertGreaterThanOrEqual($wallBefore, $clock->nowMilliseconds());
        self::assertGreaterThanOrEqual($monotonicBefore, $clock->monotonicMilliseconds());
    }
}
