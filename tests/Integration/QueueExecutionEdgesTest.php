<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\QueueSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Queue\QueueConnectionGuard;
use RaceProof\Laravel\Queue\QueueJobExecutor;

final class QueueExecutionEdgesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('queue.default', 'raceproof_testing');
        $this->app['config']->set('queue.connections.raceproof_testing', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 30,
            'after_commit' => false,
        ]);
        $this->app['config']->set('queue.failed.driver', 'null');

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_it_prepares_and_executes_one_native_queue_job(): void
    {
        $plan = $this->plan(QueueEdgeSuccessJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeSuccessJob, '', $queueName);
        $executor = $this->app->make(QueueJobExecutor::class);
        $context = new ParticipantContext($plan->runId, 'p1');

        $executor->prepare($plan, $context);
        $result = $executor->execute($plan, $context);

        self::assertTrue($result->successful());
        self::assertSame('queue', $result->execution);
        self::assertSame(1, $result->attempts);
        self::assertSame(0, $queue->size($queueName));
        self::assertSame(1, count($store->timeline($plan->runId)->ofType('queue.job_reserved')));
        $store->cleanup($plan->runId);
    }

    public function test_default_connection_token_resolves_to_the_laravel_queue_default(): void
    {
        $guard = $this->app->make(QueueConnectionGuard::class);

        self::assertSame('raceproof_testing', $guard->name('default'));
        self::assertSame($guard->resolve('raceproof_testing'), $guard->resolve('default'));

        try {
            $guard->name('invalid connection');
            self::fail('Expected the malformed queue connection name to be rejected.');
        } catch (InvalidRacePlan) {
            self::addToAssertionCount(1);
        }
    }

    public function test_missing_queue_job_fails_during_preparation(): void
    {
        $plan = $this->plan(QueueEdgeSuccessJob::class, spawnTimeoutMs: 5);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);

        try {
            $this->app->make(QueueJobExecutor::class)->prepare(
                $plan,
                new ParticipantContext($plan->runId, 'p1'),
            );
            self::fail('Expected a missing queue job to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertStringContainsString('No queued job', $exception->getMessage());
        } finally {
            $store->cleanup($plan->runId);
        }
    }

    public function test_worker_rejects_an_unexpected_job_class_without_executing_it(): void
    {
        $plan = $this->plan(QueueEdgeSuccessJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeUnexpectedJob, '', $queueName);

        try {
            $this->app->make(QueueJobExecutor::class)->prepare(
                $plan,
                new ParticipantContext($plan->runId, 'p1'),
            );
            self::fail('Expected the unexpected queue job to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertStringContainsString('unexpected job class', $exception->getMessage());
            self::assertSame(0, $queue->size($queueName));
        } finally {
            $store->cleanup($plan->runId);
        }
    }

    public function test_worker_rejects_job_owned_retry_policy_even_if_parent_validation_is_bypassed(): void
    {
        $plan = $this->plan(QueueEdgeOwnedPolicyJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeOwnedPolicyJob, '', $queueName);

        try {
            $this->app->make(QueueJobExecutor::class)->prepare(
                $plan,
                new ParticipantContext($plan->runId, 'p1'),
            );
            self::fail('Expected the job-owned retry policy to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertStringContainsString('job-owned retry policy', $exception->getMessage());
            self::assertSame(0, $queue->size($queueName));
        } finally {
            $store->cleanup($plan->runId);
        }
    }

    public function test_worker_rejects_delete_on_missing_policy_if_parent_validation_is_bypassed(): void
    {
        $plan = $this->plan(QueueEdgeDeleteMissingModelsJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeDeleteMissingModelsJob, '', $queueName);

        try {
            $this->app->make(QueueJobExecutor::class)->prepare(
                $plan,
                new ParticipantContext($plan->runId, 'p1'),
            );
            self::fail('Expected the delete-on-missing policy to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertStringContainsString('job-owned retry policy', $exception->getMessage());
            self::assertSame(0, $queue->size($queueName));
        } finally {
            $store->cleanup($plan->runId);
        }
    }

    public function test_a_job_that_fails_itself_cannot_be_reported_as_successful(): void
    {
        $plan = $this->plan(QueueEdgeSelfFailJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeSelfFailJob, '', $queueName);
        $executor = $this->app->make(QueueJobExecutor::class);
        $context = new ParticipantContext($plan->runId, 'p1');

        $executor->prepare($plan, $context);
        $result = $executor->execute($plan, $context);

        self::assertFalse($result->successful());
        self::assertSame(1, $result->attempts);
        self::assertStringContainsString('must not fail themselves', (string) $result->workerError);
        $store->cleanup($plan->runId);
    }

    public function test_a_job_that_releases_itself_cannot_escape_the_bounded_retry_policy(): void
    {
        $plan = $this->plan(QueueEdgeSelfReleaseJob::class);
        $store = $this->app->make(CoordinatorStore::class);
        $store->createRun($plan);
        $queue = $this->queue();
        $queueName = $plan->queue?->queueFor($plan->runId, 'p1');
        self::assertNotNull($queueName);
        $queue->push(new QueueEdgeSelfReleaseJob, '', $queueName);
        $executor = $this->app->make(QueueJobExecutor::class);
        $context = new ParticipantContext($plan->runId, 'p1');

        $executor->prepare($plan, $context);
        $result = $executor->execute($plan, $context);

        self::assertFalse($result->successful());
        self::assertSame(1, $result->attempts);
        self::assertStringContainsString('must not release themselves', (string) $result->workerError);
        $store->cleanup($plan->runId);
    }

    /** @param class-string $jobClass */
    private function plan(string $jobClass, int $spawnTimeoutMs = 100): RacePlan
    {
        return new RacePlan(
            runId: bin2hex(random_bytes(16)),
            participants: 2,
            request: null,
            spawnTimeoutMs: $spawnTimeoutMs,
            pollIntervalMs: 1,
            queue: new QueueSpec(
                connection: 'raceproof_testing',
                jobClasses: [
                    'p1' => $jobClass,
                    'p2' => $jobClass,
                ],
            ),
        );
    }

    private function queue(): Queue
    {
        return $this->app->make(QueueFactory::class)->connection('raceproof_testing');
    }
}

final class QueueEdgeSuccessJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

final class QueueEdgeUnexpectedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('This unexpected job must not execute.');
    }
}

final class QueueEdgeOwnedPolicyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(): void {}
}

final class QueueEdgeDeleteMissingModelsJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function handle(): void {}
}

final class QueueEdgeSelfFailJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $this->fail(new \RuntimeException('Expected self-failed job.'));
    }
}

final class QueueEdgeSelfReleaseJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $this->release();
    }
}
