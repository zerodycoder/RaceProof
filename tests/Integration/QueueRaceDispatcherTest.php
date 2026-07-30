<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use RaceProof\Laravel\Contracts\RaceRunner;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\QueueSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\EnvironmentRejected;
use RaceProof\Laravel\Exceptions\RaceExecutionFailed;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Queue\QueueConnectionGuard;
use RaceProof\Laravel\Queue\QueueJobValidator;
use RaceProof\Laravel\Queue\QueueRaceDispatcher;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use RuntimeException;

final class QueueRaceDispatcherTest extends TestCase
{
    public function test_it_dispatches_one_job_per_run_scoped_queue_and_cleans_success(): void
    {
        $queue = new DispatcherFakeQueue;
        $runner = new DispatcherFakeRunner;
        $dispatcher = $this->dispatcher($queue, $runner);

        $result = $dispatcher->run(
            participants: 2,
            queueSpec: new QueueSpec('default'),
            jobFactory: static fn (string $participantId): DispatcherQueueJob => new DispatcherQueueJob(
                $participantId,
            ),
            planFactory: $this->planFactory(),
        );

        self::assertSame($result, $runner->result);
        self::assertSame('raceproof_testing', $runner->plan?->queue?->connection);
        self::assertSame([
            "raceproof:{$result->runId}:p1",
            "raceproof:{$result->runId}:p2",
        ], $queue->pushedQueues);
        self::assertSame(4, $queue->clearCalls);
        self::assertSame([], $queue->jobs);
    }

    public function test_cleanup_failure_after_success_retains_the_result_and_is_redacted(): void
    {
        $queue = new DispatcherFakeQueue(failClearOn: 3);
        $runner = new DispatcherFakeRunner;
        $dispatcher = $this->dispatcher($queue, $runner);

        try {
            $dispatcher->run(
                participants: 2,
                queueSpec: new QueueSpec('raceproof_testing'),
                jobFactory: static fn (string $participantId): DispatcherQueueJob => new DispatcherQueueJob(
                    $participantId,
                ),
                planFactory: $this->planFactory(),
            );
            self::fail('Expected cleanup failure to fail the queue race.');
        } catch (RaceExecutionFailed $exception) {
            self::assertSame($runner->result, $exception->result);
            self::assertStringContainsString('cleanup failed', strtolower($exception->getMessage()));
            self::assertStringContainsString('token=[REDACTED]', $exception->getMessage());
            self::assertStringNotContainsString('cleanup-secret', $exception->getMessage());
        }
    }

    public function test_primary_and_cleanup_failures_are_combined_without_retaining_raw_causes(): void
    {
        $queue = new DispatcherFakeQueue(failClearOn: 3);
        $runner = new DispatcherFakeRunner(new RaceProofException('token=primary-secret'));
        $dispatcher = $this->dispatcher($queue, $runner);

        try {
            $dispatcher->run(
                participants: 2,
                queueSpec: new QueueSpec('raceproof_testing'),
                jobFactory: static fn (string $participantId): DispatcherQueueJob => new DispatcherQueueJob(
                    $participantId,
                ),
                planFactory: $this->planFactory(),
            );
            self::fail('Expected primary orchestration failure to be rethrown.');
        } catch (RaceProofException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString('token=[REDACTED]', $exception->getMessage());
            self::assertStringNotContainsString('primary-secret', $exception->getMessage());
            self::assertStringNotContainsString('cleanup-secret', $exception->getMessage());
        }
    }

    public function test_dispatch_cardinality_mismatch_fails_before_orchestration_and_still_cleans(): void
    {
        $queue = new DispatcherFakeQueue(duplicatePush: true);
        $runner = new DispatcherFakeRunner;
        $dispatcher = $this->dispatcher($queue, $runner);

        try {
            $dispatcher->run(
                participants: 2,
                queueSpec: new QueueSpec('raceproof_testing'),
                jobFactory: static fn (string $participantId): DispatcherQueueJob => new DispatcherQueueJob(
                    $participantId,
                ),
                planFactory: $this->planFactory(),
            );
            self::fail('Expected dispatch cardinality mismatch to fail closed.');
        } catch (RaceProofException $exception) {
            self::assertStringContainsString('exactly one dispatched job', $exception->getMessage());
            self::assertNull($runner->plan);
            self::assertSame([], $queue->jobs);
        }
    }

    public function test_queue_backend_failures_are_redacted_without_a_raw_exception_chain(): void
    {
        $queue = new DispatcherFakeQueue(failPush: true);
        $runner = new DispatcherFakeRunner;
        $dispatcher = $this->dispatcher($queue, $runner);

        try {
            $dispatcher->run(
                participants: 2,
                queueSpec: new QueueSpec('raceproof_testing'),
                jobFactory: static fn (string $participantId): DispatcherQueueJob => new DispatcherQueueJob(
                    $participantId,
                ),
                planFactory: $this->planFactory(),
            );
            self::fail('Expected the queue backend failure to fail closed.');
        } catch (RaceProofException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString('token=[REDACTED]', $exception->getMessage());
            self::assertStringNotContainsString('backend-secret', $exception->getMessage());
            self::assertNull($runner->plan);
        }
    }

    public function test_non_clearable_queue_connections_are_rejected_before_dispatch(): void
    {
        $config = $this->app->make(Config::class);
        $config->set('queue.connections.raceproof_testing', ['driver' => 'database']);
        $guard = new QueueConnectionGuard(
            $config,
            new DispatcherFakeQueueFactory($this->createStub(Queue::class)),
            $this->app->make(DatabaseSafety::class),
        );

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('clearable');

        $guard->resolve('raceproof_testing');
    }

    public function test_connection_resolution_failures_do_not_retain_backend_secrets(): void
    {
        $config = $this->app->make(Config::class);
        $config->set('queue.connections.raceproof_testing', ['driver' => 'redis']);
        $guard = new QueueConnectionGuard(
            $config,
            new DispatcherFailingQueueFactory,
            $this->app->make(DatabaseSafety::class),
        );

        try {
            $guard->resolve('raceproof_testing');
            self::fail('Expected the unavailable queue connection to fail closed.');
        } catch (RaceProofException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('connection-secret', $exception->getMessage());
            self::assertStringContainsString('unavailable or misconfigured', $exception->getMessage());
        }
    }

    public function test_database_queue_target_is_safety_checked_before_queue_resolution(): void
    {
        $config = $this->app->make(Config::class);
        $config->set('raceproof.database.reject_in_memory_sqlite', true);
        $config->set('database.connections.unsafe_queue', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('queue.connections.raceproof_testing', [
            'driver' => 'database',
            'connection' => 'unsafe_queue',
        ]);
        $factory = new DispatcherTrackingQueueFactory(new DispatcherFakeQueue);
        $guard = new QueueConnectionGuard(
            $config,
            $factory,
            $this->app->make(DatabaseSafety::class),
        );

        try {
            $guard->resolve('raceproof_testing');
            self::fail('Expected the unsafe queue database to be rejected.');
        } catch (EnvironmentRejected) {
            self::assertFalse($factory->resolved);
        }
    }

    public function test_malformed_database_queue_connection_is_rejected_before_resolution(): void
    {
        $config = $this->app->make(Config::class);
        $config->set('queue.connections.raceproof_testing', [
            'driver' => 'database',
            'connection' => 'invalid connection',
        ]);
        $factory = new DispatcherTrackingQueueFactory(new DispatcherFakeQueue);
        $guard = new QueueConnectionGuard(
            $config,
            $factory,
            $this->app->make(DatabaseSafety::class),
        );

        try {
            $guard->resolve('raceproof_testing');
            self::fail('Expected the malformed queue database connection to be rejected.');
        } catch (RaceProofException) {
            self::assertFalse($factory->resolved);
        }
    }

    private function dispatcher(
        DispatcherFakeQueue $queue,
        DispatcherFakeRunner $runner,
    ): QueueRaceDispatcher {
        $config = $this->app->make(Config::class);
        $config->set('queue.default', 'raceproof_testing');
        $config->set('queue.connections.raceproof_testing', ['driver' => 'database']);

        return new QueueRaceDispatcher(
            environment: $this->app->make(EnvironmentGuard::class),
            databaseSafety: $this->app->make(DatabaseSafety::class),
            connections: new QueueConnectionGuard(
                $config,
                new DispatcherFakeQueueFactory($queue),
                $this->app->make(DatabaseSafety::class),
            ),
            jobs: new QueueJobValidator,
            orchestrator: $runner,
            redactor: $this->app->make(SensitiveDataRedactor::class),
        );
    }

    /** @return \Closure(QueueSpec): RacePlan */
    private function planFactory(): \Closure
    {
        return static fn (QueueSpec $queue): RacePlan => new RacePlan(
            runId: str_repeat('a', 32),
            participants: 2,
            request: null,
            queue: $queue,
        );
    }
}

final class DispatcherFakeRunner implements RaceRunner
{
    public ?RacePlan $plan = null;

    public ?RaceResult $result = null;

    public function __construct(public readonly ?\Throwable $failure = null) {}

    public function run(RacePlan $plan): RaceResult
    {
        $this->plan = $plan;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $queue = $plan->queue;

        if ($queue === null) {
            throw new RuntimeException('Dispatcher fake received an HTTP plan.');
        }

        $participants = [];

        for ($number = 1; $number <= $plan->participants; $number++) {
            $participantId = 'p'.$number;
            $participants[] = new ParticipantResult(
                runId: $plan->runId,
                participantId: $participantId,
                status: 204,
                startedAtNs: $number,
                finishedAtNs: $number + 1,
                execution: 'queue',
                attempts: 1,
                jobClass: $queue->jobClassFor($participantId),
                queueConnection: $queue->connection,
                queueName: $queue->queueFor($plan->runId, $participantId),
            );
        }

        return $this->result = new RaceResult(
            runId: $plan->runId,
            expectedParticipants: $plan->participants,
            participants: $participants,
        );
    }
}

final class DispatcherFakeQueueFactory implements QueueFactory
{
    public function __construct(private readonly Queue $queue) {}

    public function connection($name = null): Queue
    {
        return $this->queue;
    }
}

final class DispatcherFailingQueueFactory implements QueueFactory
{
    public function connection($name = null): never
    {
        throw new RuntimeException('token=connection-secret');
    }
}

final class DispatcherTrackingQueueFactory implements QueueFactory
{
    public bool $resolved = false;

    public function __construct(private readonly Queue $queue) {}

    public function connection($name = null): Queue
    {
        $this->resolved = true;

        return $this->queue;
    }
}

final class DispatcherFakeQueue implements ClearableQueue, Queue
{
    /** @var array<string, list<mixed>> */
    public array $jobs = [];

    /** @var list<string> */
    public array $pushedQueues = [];

    public int $clearCalls = 0;

    private string $connectionName = 'raceproof_testing';

    public function __construct(
        private readonly ?int $failClearOn = null,
        private readonly bool $duplicatePush = false,
        private readonly bool $failPush = false,
    ) {}

    public function size($queue = null): int
    {
        return count($this->jobs[(string) $queue] ?? []);
    }

    public function pendingSize($queue = null): int
    {
        return $this->size($queue);
    }

    public function delayedSize($queue = null): int
    {
        return 0;
    }

    public function reservedSize($queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    public function push($job, $data = '', $queue = null): int
    {
        if ($this->failPush) {
            throw new RuntimeException('token=backend-secret');
        }

        $queue = (string) $queue;
        $this->pushedQueues[] = $queue;
        $this->jobs[$queue][] = $job;

        if ($this->duplicatePush) {
            $this->jobs[$queue][] = $job;
        }

        return $this->size($queue);
    }

    public function pushOn($queue, $job, $data = ''): int
    {
        return $this->push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): int
    {
        return $this->push($payload, '', $queue);
    }

    public function later($delay, $job, $data = '', $queue = null): int
    {
        return $this->push($job, $data, $queue);
    }

    public function laterOn($queue, $delay, $job, $data = ''): int
    {
        return $this->later($delay, $job, $data, $queue);
    }

    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    public function pop($queue = null): mixed
    {
        $queue = (string) $queue;
        $jobs = $this->jobs[$queue] ?? [];
        $job = array_shift($jobs);
        $this->jobs[$queue] = $jobs;

        return $job;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function setConnectionName($name): static
    {
        $this->connectionName = (string) $name;

        return $this;
    }

    public function clear($queue): int
    {
        $this->clearCalls++;

        if ($this->failClearOn !== null && $this->clearCalls >= $this->failClearOn) {
            throw new RuntimeException('token=cleanup-secret');
        }

        $count = $this->size($queue);
        unset($this->jobs[(string) $queue]);

        return $count;
    }
}

final class DispatcherQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $participantId) {}

    public function handle(): void {}
}
