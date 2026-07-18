<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConcurrentKernelRunnerTest extends TestCase
{
    public function test_three_real_laravel_processes_rendezvous_at_a_race_point(): void
    {
        $script = __DIR__.'/../Fixtures/app/run-race.php';
        $process = new Process([PHP_BINARY, $script], dirname(__DIR__, 2), timeout: 30);
        $process->mustRun();

        /** @var array<string, mixed> $result */
        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $result['expected_participants']);
        self::assertSame(3, count($result['participants']));
        self::assertSame(3, $result['statuses']['200']);
        self::assertFalse($result['timed_out']);
        self::assertNull($result['artifact_path']);

        foreach ($result['participants'] as $participant) {
            self::assertNull($participant['worker_error']);
            self::assertSame(200, $participant['status']);
        }
    }
}
