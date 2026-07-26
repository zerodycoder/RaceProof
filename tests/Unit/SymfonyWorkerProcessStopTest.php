<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Execution\SymfonyWorkerProcess;
use Symfony\Component\Process\Process;

final class SymfonyWorkerProcessStopTest extends TestCase
{
    public function test_stop_terminates_a_running_process_before_wait_returns(): void
    {
        $process = new SymfonyWorkerProcess(new Process([
            PHP_BINARY,
            '-r',
            'usleep(5_000_000);',
        ]));
        $startedAt = hrtime(true);
        $process->start();

        self::assertTrue($process->isRunning());

        $process->stop(0.01);
        $process->wait();

        self::assertLessThan(1_000, (hrtime(true) - $startedAt) / 1_000_000);
        self::assertFalse($process->isRunning());
        self::assertNotNull($process->exitCode());
    }

    public function test_stop_is_a_no_op_before_start(): void
    {
        $inner = $this->createMock(Process::class);
        $inner->expects(self::never())->method('isRunning');
        $inner->expects(self::never())->method('stop');

        $process = new SymfonyWorkerProcess($inner);
        $process->stop(0.01);

        self::assertFalse($process->isRunning());
        self::assertNull($process->exitCode());
    }

    public function test_wait_and_exit_code_are_cached_after_start(): void
    {
        $inner = $this->createMock(Process::class);
        $inner->expects(self::once())->method('start');
        $inner->expects(self::once())->method('wait')->willReturn(7);
        $inner->expects(self::never())->method('getExitCode');

        $process = new SymfonyWorkerProcess($inner);
        $process->start();

        self::assertSame(7, $process->wait());
        self::assertSame(7, $process->wait());
        self::assertSame(7, $process->exitCode());
    }
}
