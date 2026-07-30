<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Contracts\RaceRunner;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceExecutionFailed;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

final readonly class RaceOrchestrator implements RaceRunner
{
    public function __construct(
        private Config $config,
        private CoordinatorStore $store,
        private EnvironmentGuard $environment,
        private DatabaseSafety $databaseSafety,
        private WorkerProcessFactory $processFactory,
        private RaceClock $clock,
        private SensitiveDataRedactor $redactor,
        private ReportArchive $archive,
    ) {}

    public function run(RacePlan $plan): RaceResult
    {
        $this->environment->ensureEnabled();
        $this->databaseSafety->validate();
        $this->store->createRun($plan);

        /** @var array<string, WorkerProcess> $processes */
        $processes = [];
        $timedOut = false;
        $spawnDeadline = $this->clock->nowNs() + ($plan->spawnTimeoutMs * 1_000_000);

        try {
            for ($number = 1; $number <= $plan->participants; $number++) {
                $participantId = 'p'.$number;
                $process = $this->processFactory->create($plan->runId, $participantId);
                $process->start();
                $processes[$participantId] = $process;
                $this->store->recordEvent(TimelineEvent::make(
                    $plan->runId,
                    'participant.spawned',
                    $participantId,
                ));

                if ($plan->queue !== null) {
                    $this->waitUntilReady($plan, $processes, $number, $spawnDeadline);
                }
            }

            $this->waitUntilReady($plan, $processes, $plan->participants, $spawnDeadline);
            $this->store->releaseStart($plan->runId);

            $deadline = $this->clock->nowNs() + ($plan->runTimeoutMs * 1_000_000);
            $released = [];

            while (true) {
                foreach ($plan->checkpoints as $checkpoint) {
                    if (! isset($released[$checkpoint]) && $this->store->checkpointCount($plan->runId, $checkpoint) >= $plan->participants) {
                        $this->store->releaseCheckpoint($plan->runId, $checkpoint);
                        $released[$checkpoint] = true;
                    }
                }

                if ($this->allStopped($processes)) {
                    break;
                }

                if ($this->clock->nowNs() >= $deadline) {
                    $timedOut = true;
                    $this->store->recordEvent(TimelineEvent::make($plan->runId, 'run.timed_out', data: [
                        'timeout_ms' => $plan->runTimeoutMs,
                    ]));
                    break;
                }

                $this->clock->sleepMilliseconds($plan->pollIntervalMs);
            }

            $this->settleAll($processes, $timedOut);
            $this->recordProcessExits($plan, $processes, $timedOut ? 'run_timeout' : 'completed');
            $results = $this->collectResults($plan, $processes, $timedOut);
            $clean = ! $timedOut
                && count($results) === $plan->participants
                && array_filter(
                    $results,
                    fn (ParticipantResult $result): bool => $result->workerError !== null
                        || $result->exceptionClass !== null,
                ) === [];
            $cleanupRequested = $clean && ConfigValue::boolean($this->config, 'raceproof.runner.cleanup_successful_runs', true);
            $this->store->recordEvent(TimelineEvent::make($plan->runId, 'run.completed', data: [
                'timed_out' => $timedOut,
                'result_count' => count($results),
                'failure_count' => count(array_filter($results, fn (ParticipantResult $result): bool => ! $result->successful())),
            ]));

            $timeline = $this->store->timeline($plan->runId);
            $cleanup = $cleanupRequested && $timeline->warnings === [];

            if ($cleanup) {
                $this->store->recordEvent(TimelineEvent::make($plan->runId, 'run.cleanup_started', data: [
                    'reason' => 'successful_run',
                ]));
                $timeline = $this->store->timeline($plan->runId);
            }

            $result = new RaceResult(
                runId: $plan->runId,
                expectedParticipants: $plan->participants,
                participants: $results,
                timedOut: $timedOut,
                artifactPath: $cleanup ? null : $this->artifactPath($plan),
                timeline: $timeline,
            );
            $this->archive->store($result);

            if ($cleanup) {
                $this->store->cleanup($plan->runId);
            }

            return $result;
        } catch (Throwable $exception) {
            return $this->failedRun($plan, $processes, $timedOut, $exception);
        }
    }

    /**
     * @param  array<string, WorkerProcess>  $processes
     */
    private function failedRun(RacePlan $plan, array $processes, bool $timedOut, Throwable $exception): never
    {
        $secondaryFailures = [];

        try {
            $this->settleAll($processes, true);
        } catch (Throwable $settlementException) {
            $secondaryFailures[] = 'worker settlement: '.$settlementException->getMessage();
        }

        try {
            $this->recordProcessExits($plan, $processes, $timedOut ? 'run_timeout' : 'parent_failure');
        } catch (Throwable $timelineException) {
            $secondaryFailures[] = 'process-exit evidence: '.$timelineException->getMessage();
        }

        $results = [];

        try {
            $results = $this->collectResults($plan, $processes, $timedOut);
        } catch (Throwable $resultException) {
            $secondaryFailures[] = 'result collection: '.$resultException->getMessage();
        }

        try {
            $this->store->recordEvent(TimelineEvent::make($plan->runId, 'run.failed', data: [
                'exception_class' => $exception::class,
                'message' => $this->redactor->diagnostic($exception->getMessage()),
                'secondary_failure_count' => count($secondaryFailures),
            ]));
        } catch (Throwable $timelineException) {
            $secondaryFailures[] = 'failure evidence: '.$timelineException->getMessage();
        }

        $timeline = null;

        try {
            $timeline = $this->store->timeline($plan->runId);
        } catch (Throwable $timelineException) {
            $secondaryFailures[] = 'timeline read: '.$timelineException->getMessage();
        }

        $message = $this->redactor->diagnostic($exception->getMessage());

        if ($secondaryFailures !== []) {
            $message .= ' Secondary evidence failures: '.$this->redactor->diagnostic(implode('; ', $secondaryFailures)).'.';
        }

        $result = new RaceResult(
            runId: $plan->runId,
            expectedParticipants: $plan->participants,
            participants: $results,
            timedOut: $timedOut,
            artifactPath: $this->artifactPath($plan),
            timeline: $timeline,
        );

        try {
            $this->archive->store($result);
        } catch (Throwable $archiveException) {
            $message .= ' Studio archive failure: '.$this->redactor->diagnostic($archiveException->getMessage()).'.';
        }

        throw new RaceExecutionFailed($message, $result, $exception);
    }

    /** @param array<string, WorkerProcess> $processes */
    private function waitUntilReady(
        RacePlan $plan,
        array $processes,
        int $expectedReady,
        int $deadline,
    ): void {
        while ($this->store->readyCount($plan->runId) < $expectedReady) {
            foreach ($processes as $participantId => $process) {
                if (! $process->isRunning()) {
                    $output = $this->workerOutput($process);
                    $status = $this->exitStatus($process);
                    $this->store->recordEvent(TimelineEvent::make(
                        $plan->runId,
                        'participant.early_exit',
                        $participantId,
                        data: [
                            'exit_code' => $process->exitCode(),
                            'output' => $output,
                        ],
                    ));
                    throw new RaceProofException(
                        "Worker [{$participantId}] exited before the start barrier{$status}".($output === '' ? '.' : ": {$output}"),
                    );
                }
            }

            if ($this->clock->nowNs() >= $deadline) {
                $ready = $this->store->readyCount($plan->runId);
                $this->store->recordEvent(TimelineEvent::make($plan->runId, 'run.spawn_timed_out', data: [
                    'ready_count' => $ready,
                    'expected_count' => $plan->participants,
                    'timeout_ms' => $plan->spawnTimeoutMs,
                ]));
                throw new RaceProofException(
                    "Only {$ready}/{$plan->participants} workers became ready before the spawn timeout.",
                );
            }

            $this->clock->sleepMilliseconds($plan->pollIntervalMs);
        }
    }

    /** @param array<string, WorkerProcess> $processes */
    private function allStopped(array $processes): bool
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, WorkerProcess> $processes */
    private function settleAll(array $processes, bool $stopRunning): void
    {
        $failure = null;

        if ($stopRunning) {
            foreach ($processes as $process) {
                try {
                    if ($process->isRunning()) {
                        $process->stop(0.5);
                    }
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                }
            }
        }

        foreach ($processes as $process) {
            try {
                $process->wait();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param array<string, WorkerProcess> $processes */
    private function recordProcessExits(RacePlan $plan, array $processes, string $reason): void
    {
        foreach ($processes as $participantId => $process) {
            $this->store->recordEvent(TimelineEvent::make(
                $plan->runId,
                'participant.exited',
                $participantId,
                data: [
                    'exit_code' => $process->exitCode(),
                    'reason' => $reason,
                    'output' => $this->workerOutput($process),
                ],
            ));
        }
    }

    /**
     * @param  array<string, WorkerProcess>  $processes
     * @return list<ParticipantResult>
     */
    private function collectResults(RacePlan $plan, array $processes, bool $timedOut): array
    {
        $stored = $this->store->results($plan->runId);
        $byParticipant = [];

        foreach ($stored as $result) {
            $byParticipant[$result->participantId] = $result;
        }

        foreach ($processes as $participantId => $process) {
            if (isset($byParticipant[$participantId])) {
                continue;
            }

            $output = $this->workerOutput($process);
            $message = $timedOut
                ? 'Worker was terminated after the run timeout'
                : 'Worker exited without a result';
            $message .= $this->exitStatus($process).'.';

            if ($output !== '') {
                $message .= ' '.$output;
            }

            $byParticipant[$participantId] = ParticipantResult::workerFailure(
                $plan->runId,
                $participantId,
                $message,
                plan: $plan,
            );
        }

        ksort($byParticipant, SORT_NATURAL);

        return array_values($byParticipant);
    }

    private function exitStatus(WorkerProcess $process): string
    {
        $exitCode = $process->exitCode();

        return $exitCode === null ? '' : " with exit code {$exitCode}";
    }

    private function workerOutput(WorkerProcess $process): string
    {
        return $this->redactor->workerOutput($process->errorOutput(), $process->output());
    }

    private function artifactPath(RacePlan $plan): string
    {
        return $this->store->artifactReference($plan->runId);
    }
}
