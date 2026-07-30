<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class QueuedKernelRunnerTest extends TestCase
{
    public function test_real_database_queue_jobs_checkpoint_retry_and_exhaust_through_independent_workers(): void
    {
        $script = __DIR__.'/../Fixtures/app/run-queue-race.php';
        $process = new Process([PHP_BINARY, $script], dirname(__DIR__, 2), timeout: 60);
        $process->mustRun();

        /** @var array<string, mixed> $evidence */
        $evidence = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $evidence['rows']['checkpointed']);
        self::assertSame(2, $evidence['rows']['retried']);
        self::assertSame(0, $evidence['rows']['remaining_jobs']);
        self::assertSame(['204' => 3], $evidence['checkpointed']['statuses']);
        self::assertSame(['204' => 2], $evidence['retried']['statuses']);
        self::assertSame([], $evidence['exhausted']['statuses']);

        foreach ($evidence['checkpointed']['participants'] as $participant) {
            self::assertSame('queue', $participant['execution']);
            self::assertSame(1, $participant['attempts']);
            self::assertNull($participant['worker_error']);
            self::assertNull($participant['exception_class']);
        }

        foreach ($evidence['retried']['participants'] as $participant) {
            self::assertSame('queue', $participant['execution']);
            self::assertSame(2, $participant['attempts']);
            self::assertNull($participant['worker_error']);
            self::assertNull($participant['exception_class']);
        }

        foreach ($evidence['exhausted']['participants'] as $participant) {
            self::assertSame('queue', $participant['execution']);
            self::assertSame(2, $participant['attempts']);
            self::assertNull($participant['worker_error']);
            self::assertSame(\RuntimeException::class, $participant['exception_class']);
            self::assertStringContainsString('Expected exhausted queue job failure.', $participant['exception_message']);
        }

        $checkpointEvents = array_column($evidence['checkpointed']['timeline']['events'], 'type');
        $retryEvents = array_column($evidence['retried']['timeline']['events'], 'type');
        self::assertContains('checkpoint.released', $checkpointEvents);
        self::assertSame(2, count(array_filter(
            $retryEvents,
            static fn (string $type): bool => $type === 'queue.attempt_failed',
        )));
        self::assertSame(2, count(array_filter(
            $retryEvents,
            static fn (string $type): bool => $type === 'queue.attempt_completed',
        )));
    }
}
