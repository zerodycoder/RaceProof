<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\ParticipantClock;
use RaceProof\Laravel\Contracts\ParticipantExecutor;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

/**
 * @internal Executes one isolated participant queue through Laravel's Worker.
 */
final class QueueJobExecutor implements ParticipantExecutor
{
    /** @var array<string, Job> */
    private array $reserved = [];

    public function __construct(
        private readonly QueueConnectionGuard $connections,
        private readonly Worker $worker,
        private readonly CoordinatorStore $store,
        private readonly ParticipantClock $participantClock,
        private readonly RaceClock $clock,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function prepare(RacePlan $plan, ParticipantContext $context): void
    {
        $spec = $plan->queue;

        if ($spec === null) {
            throw new InvalidRacePlan('Queue preparation requires queue plan configuration.');
        }

        $connection = $this->connections->resolve($spec->connection);
        $queueName = $spec->queueFor($plan->runId, $context->participantId);
        $deadline = $this->clock->nowNs() + ($plan->spawnTimeoutMs * 1_000_000);
        $job = $this->popUntilAvailable($connection, $queueName, $deadline, $plan->pollIntervalMs);

        if (! $job instanceof Job) {
            throw new InvalidRacePlan('No queued job was available while preparing this participant.');
        }

        $this->validateReservedJob($job, $spec->jobClassFor($context->participantId));
        $this->reserved[$this->reservationKey($context)] = $job;
        $this->store->recordEvent(TimelineEvent::make(
            $plan->runId,
            'queue.job_reserved',
            $context->participantId,
            data: [
                'connection' => $spec->connection,
                'queue' => $queueName,
                'job_class' => $job->resolveName(),
            ],
        ));
    }

    public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult
    {
        $spec = $plan->queue;

        if ($spec === null) {
            throw new InvalidRacePlan('Queue execution requires queue plan configuration.');
        }

        $connection = $this->connections->resolve($spec->connection);
        $queueName = $spec->queueFor($plan->runId, $context->participantId);
        $expectedClass = $spec->jobClassFor($context->participantId);
        $startedAt = $this->participantClock->nowNs();
        $deadline = $this->clock->nowNs() + ($plan->runTimeoutMs * 1_000_000);
        $attempts = 0;
        $lastException = null;
        $workerError = null;
        $succeeded = false;

        $this->store->recordEvent(TimelineEvent::make(
            $plan->runId,
            'queue.participant_started',
            $context->participantId,
            data: [
                'connection' => $spec->connection,
                'queue' => $queueName,
                'job_class' => $expectedClass,
                'max_attempts' => $spec->maxAttempts,
                'backoff_seconds' => $spec->backoffSeconds,
            ],
        ));

        while ($attempts < $spec->maxAttempts && $this->clock->nowNs() < $deadline) {
            $reservationKey = $this->reservationKey($context);
            $job = $this->reserved[$reservationKey] ?? null;
            unset($this->reserved[$reservationKey]);

            if (! $job instanceof Job) {
                try {
                    $job = $this->popUntilAvailable($connection, $queueName, $deadline, $plan->pollIntervalMs);
                } catch (Throwable $exception) {
                    $workerError = 'The run-scoped queue could not reserve its job: '
                        .$this->redactor->diagnostic($exception->getMessage());
                    break;
                }
            }

            if (! $job instanceof Job) {
                $workerError = $attempts === 0
                    ? 'No queued job was available for this participant.'
                    : 'A released queue job did not become available before the run deadline.';
                break;
            }

            $attempts++;
            try {
                $this->validateReservedJob($job, $expectedClass);
            } catch (InvalidRacePlan $exception) {
                $workerError = $exception->getMessage();
                break;
            }

            $jobClass = $job->resolveName();
            $this->store->recordEvent(TimelineEvent::make(
                $plan->runId,
                'queue.attempt_started',
                $context->participantId,
                data: [
                    'attempt' => $attempts,
                    'job_class' => $jobClass,
                ],
            ));

            try {
                $this->worker->process(
                    $spec->connection,
                    $job,
                    new WorkerOptions(
                        name: "raceproof-{$plan->runId}-{$context->participantId}",
                        backoff: $spec->backoffSeconds,
                        timeout: max(1, (int) ceil($plan->runTimeoutMs / 1_000)),
                        sleep: 0,
                        maxTries: $spec->maxAttempts,
                        force: false,
                        stopWhenEmpty: true,
                        maxJobs: 1,
                        maxTime: max(1, (int) ceil($plan->runTimeoutMs / 1_000)),
                    ),
                );

                if ($job->hasFailed()) {
                    $workerError = 'Queue jobs must not fail themselves during a queue race.';
                    break;
                }

                if ($job->isReleased()) {
                    $workerError = 'Queue jobs must not release themselves during a queue race.';
                    break;
                }

                $succeeded = true;
                $this->store->recordEvent(TimelineEvent::make(
                    $plan->runId,
                    'queue.attempt_completed',
                    $context->participantId,
                    data: ['attempt' => $attempts],
                ));
                break;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $this->store->recordEvent(TimelineEvent::make(
                    $plan->runId,
                    'queue.attempt_failed',
                    $context->participantId,
                    data: [
                        'attempt' => $attempts,
                        'exception_class' => $exception::class,
                        'message' => $this->redactor->diagnostic($exception->getMessage()),
                        'released' => $job->isReleased(),
                        'failed' => $job->hasFailed(),
                    ],
                ));

                if ($job->hasFailed() || $job->isDeleted()) {
                    break;
                }

                if (! $job->isReleased()) {
                    $workerError = 'Laravel did not release or fail the exceptional queue job.';
                    break;
                }
            }
        }

        if (! $succeeded && $workerError === null && $lastException === null) {
            $workerError = 'Queue execution exceeded its bounded attempt or run-time policy.';
        }

        $finishedAt = $this->participantClock->nowNs();
        $outcome = $succeeded
            ? 'success'
            : ($workerError === null ? 'application_exception' : 'worker_error');

        $this->store->recordEvent(TimelineEvent::make(
            $plan->runId,
            'queue.participant_completed',
            $context->participantId,
            data: [
                'outcome' => $outcome,
                'attempts' => $attempts,
            ],
        ));

        return new ParticipantResult(
            runId: $plan->runId,
            participantId: $context->participantId,
            status: $succeeded ? 204 : null,
            startedAtNs: $startedAt,
            finishedAtNs: $finishedAt,
            exceptionClass: ! $succeeded && $workerError === null && $lastException !== null
                ? $lastException::class
                : null,
            exceptionMessage: ! $succeeded && $workerError === null && $lastException !== null
                ? $this->redactor->diagnostic($lastException->getMessage())
                : null,
            workerError: $workerError,
            execution: 'queue',
            attempts: $attempts,
            jobClass: $expectedClass,
            queueConnection: $spec->connection,
            queueName: $queueName,
        );
    }

    private function popUntilAvailable(
        Queue $connection,
        string $queue,
        int $deadline,
        int $pollIntervalMs,
    ): ?Job {
        $lastFailure = null;

        do {
            try {
                $job = $connection->pop($queue);
                $lastFailure = null;
            } catch (Throwable $exception) {
                $job = null;
                $lastFailure = $exception;
            }

            if ($job instanceof Job) {
                return $job;
            }

            if ($this->clock->nowNs() >= $deadline) {
                if ($lastFailure !== null) {
                    throw $lastFailure;
                }

                return null;
            }

            $this->clock->sleepMilliseconds($pollIntervalMs);
        } while (true);
    }

    private function usesRaceProofRetryPolicy(Job $job): bool
    {
        if (
            $job->maxTries() !== null
            || $job->maxExceptions() !== null
            || $job->timeout() !== null
            || $job->retryUntil() !== null
        ) {
            return false;
        }

        $payload = $job->payload();

        return ($payload['backoff'] ?? null) === null
            && ($payload['failOnTimeout'] ?? false) === false
            && ($payload['deleteWhenMissingModels'] ?? false) === false;
    }

    private function validateReservedJob(Job $job, string $expectedClass): void
    {
        if (! hash_equals($expectedClass, $job->resolveName())) {
            $job->delete();
            throw new InvalidRacePlan('The run-scoped queue returned an unexpected job class.');
        }

        if (! $this->usesRaceProofRetryPolicy($job)) {
            $job->delete();
            throw new InvalidRacePlan('The queued job contains an unsupported job-owned retry policy.');
        }
    }

    private function reservationKey(ParticipantContext $context): string
    {
        return $context->runId.':'.$context->participantId;
    }
}
