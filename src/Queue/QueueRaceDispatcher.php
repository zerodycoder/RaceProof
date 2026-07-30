<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Queue;

use Closure;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Queue;
use RaceProof\Laravel\Contracts\RaceRunner;
use RaceProof\Laravel\Data\QueueSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Exceptions\RaceExecutionFailed;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

/**
 * @internal Job factories remain parent-local and never enter the serialized plan.
 */
final readonly class QueueRaceDispatcher
{
    public function __construct(
        private EnvironmentGuard $environment,
        private DatabaseSafety $databaseSafety,
        private QueueConnectionGuard $connections,
        private QueueJobValidator $jobs,
        private RaceRunner $orchestrator,
        private SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  Closure(string): mixed  $jobFactory
     * @param  Closure(QueueSpec): RacePlan  $planFactory
     */
    public function run(
        int $participants,
        QueueSpec $queueSpec,
        Closure $jobFactory,
        Closure $planFactory,
    ): RaceResult {
        if ($participants < 2 || $participants > 100) {
            throw new InvalidRacePlan('Participants must be between 2 and 100.');
        }

        $this->environment->ensureEnabled();
        $this->databaseSafety->validate();
        $queueSpec = $queueSpec->withConnection($this->connections->name($queueSpec->connection));
        $connection = $this->connections->resolve($queueSpec->connection);
        $jobs = [];
        $jobClasses = [];
        $identities = [];

        for ($number = 1; $number <= $participants; $number++) {
            $participantId = 'p'.$number;

            try {
                $candidate = $jobFactory($participantId);
            } catch (Throwable $exception) {
                throw new InvalidRacePlan(
                    'The queue job factory failed: '.$this->redactor->diagnostic($exception->getMessage()),
                );
            }

            $job = $this->jobs->validate($candidate);
            $identity = spl_object_id($job);

            if (isset($identities[$identity])) {
                throw new InvalidRacePlan('Queue job factories must return a distinct job object per participant.');
            }

            $identities[$identity] = true;
            $jobs[$participantId] = $job;
            $jobClasses[$participantId] = $job::class;
        }

        $plan = $planFactory($queueSpec->withJobClasses($jobClasses));
        $result = null;
        $primaryFailure = null;

        try {
            $this->prepareQueues($connection, $plan);

            foreach ($jobs as $participantId => $job) {
                $queueName = $plan->queue?->queueFor($plan->runId, $participantId);

                if ($queueName === null) {
                    throw new InvalidRacePlan('Queue race plan is missing queue configuration.');
                }

                $connection->push($job, '', $queueName);

                if ($connection->size($queueName) !== 1) {
                    throw new RaceProofException('A run-scoped queue did not contain exactly one dispatched job.');
                }
            }

            $result = $this->orchestrator->run($plan);
        } catch (Throwable $exception) {
            $primaryFailure = $exception;
        }

        $cleanupFailure = $this->cleanupQueues($connection, $plan);

        if ($primaryFailure !== null) {
            if ($cleanupFailure === null) {
                if (! $primaryFailure instanceof RaceProofException) {
                    throw new RaceProofException(
                        'Queue race execution failed: '
                        .$this->redactor->diagnostic($primaryFailure->getMessage()),
                    );
                }

                throw $primaryFailure;
            }

            $message = $this->redactor->diagnostic($primaryFailure->getMessage())
                .' Queue cleanup also failed: '
                .$this->redactor->diagnostic($cleanupFailure->getMessage());

            if ($primaryFailure instanceof RaceExecutionFailed) {
                throw new RaceExecutionFailed($message, $primaryFailure->result);
            }

            throw new RaceProofException($message);
        }

        if (! $result instanceof RaceResult) {
            throw new RaceProofException('Queue race completed without a result.');
        }

        if ($cleanupFailure !== null) {
            throw new RaceExecutionFailed(
                'Run-scoped queue cleanup failed: '.$this->redactor->diagnostic($cleanupFailure->getMessage()),
                $result,
            );
        }

        return $result;
    }

    private function prepareQueues(Queue&ClearableQueue $connection, RacePlan $plan): void
    {
        for ($number = 1; $number <= $plan->participants; $number++) {
            $queueName = $plan->queue?->queueFor($plan->runId, 'p'.$number);

            if ($queueName === null) {
                throw new InvalidRacePlan('Queue race plan is missing queue configuration.');
            }

            $this->clear($connection, $queueName);

            if ($connection->size($queueName) !== 0) {
                throw new RaceProofException('A run-scoped queue could not be prepared empty.');
            }
        }
    }

    private function cleanupQueues(Queue&ClearableQueue $connection, RacePlan $plan): ?Throwable
    {
        $failure = null;

        for ($number = 1; $number <= $plan->participants; $number++) {
            try {
                $queueName = $plan->queue?->queueFor($plan->runId, 'p'.$number);

                if ($queueName !== null) {
                    $this->clear($connection, $queueName);
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }

    private function clear(Queue&ClearableQueue $connection, string $queue): void
    {
        $connection->clear($queue);
    }
}
