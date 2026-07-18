<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Execution\SymfonyWorkerProcess;
use RaceProof\Laravel\Support\SystemRaceClock;
use Symfony\Component\Process\Process;

final class SymfonyWorkerProcessTest extends TestCase
{
    public function test_it_exposes_output_error_and_exit_code_and_wait_is_idempotent(): void
    {
        $process = new SymfonyWorkerProcess(new Process([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(7);',
        ]));

        self::assertNull($process->exitCode());
        $process->start();
        self::assertSame(7, $process->wait());
        self::assertSame(7, $process->wait());
        self::assertSame(7, $process->exitCode());
        self::assertSame('out', $process->output());
        self::assertSame('err', $process->errorOutput());
        self::assertFalse($process->isRunning());
    }

    public function test_wait_rejects_a_process_that_was_not_started(): void
    {
        $process = new SymfonyWorkerProcess(new Process([PHP_BINARY, '-r', 'exit(0);']));

        $this->expectException(LogicException::class);

        $process->wait();
    }

    public function test_system_clock_provides_monotonic_time_and_zero_duration_sleep(): void
    {
        $clock = new SystemRaceClock;
        $before = $clock->nowNs();
        $clock->sleepMilliseconds(0);

        self::assertGreaterThanOrEqual($before, $clock->nowNs());
    }
}
