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
        $process->start();

        self::assertTrue($process->isRunning());

        $process->stop(0.01);
        $process->wait();

        self::assertFalse($process->isRunning());
        self::assertNotNull($process->exitCode());
    }
}
