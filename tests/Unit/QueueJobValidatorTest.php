<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Queue\QueueJobValidator;

final class QueueJobValidatorTest extends TestCase
{
    public function test_it_accepts_a_plain_distinct_should_queue_job(): void
    {
        $job = new ValidQueueRaceJob('p1');

        self::assertSame($job, (new QueueJobValidator)->validate($job));
    }

    #[DataProvider('invalidJobs')]
    public function test_it_rejects_unsupported_job_shapes(mixed $job): void
    {
        $this->expectException(InvalidRacePlan::class);

        (new QueueJobValidator)->validate($job);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidJobs(): iterable
    {
        yield 'not an object' => [ValidQueueRaceJob::class];
        yield 'not queued' => [new \stdClass];
        yield 'unique' => [new UniqueQueueRaceJob];
        yield 'unique until processing' => [new UniqueUntilProcessingQueueRaceJob];
        yield 'encrypted interface' => [new EncryptedQueueRaceJob];
        yield 'after-commit interface' => [new AfterCommitQueueRaceJob];
        yield 'connection override' => [(new ValidQueueRaceJob('p1'))->onConnection('redis')];
        yield 'queue override' => [(new ValidQueueRaceJob('p1'))->onQueue('orders')];
        yield 'delay' => [(new ValidQueueRaceJob('p1'))->delay(1)];
        yield 'after commit' => [(new ValidQueueRaceJob('p1'))->afterCommit()];
        yield 'tries' => [new JobOwnedTriesQueueRaceJob];
        yield 'max exceptions' => [new JobOwnedMaxExceptionsQueueRaceJob];
        yield 'backoff property' => [new JobOwnedBackoffPropertyQueueRaceJob];
        yield 'timeout' => [new JobOwnedTimeoutQueueRaceJob];
        yield 'fail on timeout' => [new JobOwnedFailOnTimeoutQueueRaceJob];
        yield 'retry until property' => [new JobOwnedRetryUntilPropertyQueueRaceJob];
        yield 'encrypted property' => [new JobOwnedEncryptionQueueRaceJob];
        yield 'delete missing models' => [new JobOwnedMissingModelPolicyQueueRaceJob];
        yield 'tries method' => [new JobOwnedTriesMethodQueueRaceJob];
        yield 'retry until method' => [new JobOwnedRetryUntilMethodQueueRaceJob];
        yield 'backoff method' => [new JobOwnedBackoffQueueRaceJob];
        yield 'chain' => [(new ValidQueueRaceJob('p1'))->chain([new ValidQueueRaceJob('p2')])];
        yield 'chain connection' => [(new ValidQueueRaceJob('p1'))->allOnConnection('redis')];
        yield 'chain queue' => [(new ValidQueueRaceJob('p1'))->allOnQueue('orders')];
        yield 'batch' => [(new BatchedQueueRaceJob)->withBatchId('batch-id')];
        yield 'tries attribute' => [new AttributedTriesQueueRaceJob];
        yield 'connection attribute' => [new AttributedConnectionQueueRaceJob];
    }
}

final class ValidQueueRaceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $participant) {}
}

final class UniqueQueueRaceJob implements ShouldBeUnique, ShouldQueue {}

final class UniqueUntilProcessingQueueRaceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue {}

final class EncryptedQueueRaceJob implements ShouldBeEncrypted, ShouldQueue {}

final class AfterCommitQueueRaceJob implements ShouldQueueAfterCommit {}

final class JobOwnedTriesQueueRaceJob implements ShouldQueue
{
    public int $tries = 2;
}

final class JobOwnedMaxExceptionsQueueRaceJob implements ShouldQueue
{
    public int $maxExceptions = 2;
}

final class JobOwnedBackoffPropertyQueueRaceJob implements ShouldQueue
{
    public int $backoff = 1;
}

final class JobOwnedTimeoutQueueRaceJob implements ShouldQueue
{
    public int $timeout = 10;
}

final class JobOwnedFailOnTimeoutQueueRaceJob implements ShouldQueue
{
    public bool $failOnTimeout = true;
}

final class JobOwnedRetryUntilPropertyQueueRaceJob implements ShouldQueue
{
    public int $retryUntil = 1;
}

final class JobOwnedEncryptionQueueRaceJob implements ShouldQueue
{
    public bool $shouldBeEncrypted = true;
}

final class JobOwnedMissingModelPolicyQueueRaceJob implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;
}

final class JobOwnedTriesMethodQueueRaceJob implements ShouldQueue
{
    public function tries(): int
    {
        return 2;
    }
}

final class JobOwnedRetryUntilMethodQueueRaceJob implements ShouldQueue
{
    public function retryUntil(): int
    {
        return 1;
    }
}

final class JobOwnedBackoffQueueRaceJob implements ShouldQueue
{
    public function backoff(): int
    {
        return 1;
    }
}

final class BatchedQueueRaceJob implements ShouldQueue
{
    use Batchable;
}

#[Tries(2)]
final class AttributedTriesQueueRaceJob implements ShouldQueue {}

#[Connection('redis')]
final class AttributedConnectionQueueRaceJob implements ShouldQueue {}
