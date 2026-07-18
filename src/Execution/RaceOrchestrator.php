<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use Throwable;

final readonly class RaceOrchestrator
{
    public function __construct(
        private Config $config,
        private CoordinatorStore $store,
        private EnvironmentGuard $environment,
        private DatabaseSafety $databaseSafety,
        private WorkerProcessFactory $processFactory,
        private RaceClock $clock,
    ) {}

    public function run(RacePlan $plan): RaceResult
    {
        $this->environment->ensureEnabled();
        $this->databaseSafety->validate();
        $this->store->createRun($plan);

        /** @var array<string, WorkerProcess> $processes */
        $processes = [];
        $timedOut = false;

        try {
            for ($number = 1; $number <= $plan->participants; $number++) {
                $participantId = 'p'.$number;
                $process = $this->processFactory->create($plan->runId, $participantId);
                $process->start();
                $processes[$participantId] = $process;
            }

            $this->waitUntilReady($plan, $processes);
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
                    break;
                }

                $this->clock->sleepMilliseconds($plan->pollIntervalMs);
            }

            $this->settleAll($processes, $timedOut);
            $results = $this->collectResults($plan, $processes, $timedOut);
            $clean = ! $timedOut
                && count($results) === $plan->participants
                && array_filter($results, fn (ParticipantResult $result): bool => $result->workerError !== null) === [];
            $cleanup = $clean && ConfigValue::boolean($this->config, 'raceproof.runner.cleanup_successful_runs', true);
            $artifactPath = $cleanup ? null : rtrim($this->store->basePath(), '/\\').'/'.$plan->runId;

            if ($cleanup) {
                $this->store->cleanup($plan->runId);
            }

            return new RaceResult(
                runId: $plan->runId,
                expectedParticipants: $plan->participants,
                participants: $results,
                timedOut: $timedOut,
                artifactPath: $artifactPath,
            );
        } catch (Throwable $exception) {
            try {
                $this->settleAll($processes, true);
            } catch (Throwable $cleanupException) {
                throw new RaceProofException(
                    $exception->getMessage().' Worker cleanup also failed: '.$cleanupException->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /** @param array<string, WorkerProcess> $processes */
    private function waitUntilReady(RacePlan $plan, array $processes): void
    {
        $deadline = $this->clock->nowNs() + ($plan->spawnTimeoutMs * 1_000_000);

        while ($this->store->readyCount($plan->runId) < $plan->participants) {
            foreach ($processes as $participantId => $process) {
                if (! $process->isRunning()) {
                    $output = $this->workerOutput($process);
                    $status = $this->exitStatus($process);
                    throw new RaceProofException(
                        "Worker [{$participantId}] exited before the start barrier{$status}".($output === '' ? '.' : ": {$output}"),
                    );
                }
            }

            if ($this->clock->nowNs() >= $deadline) {
                throw new RaceProofException(
                    "Only {$this->store->readyCount($plan->runId)}/{$plan->participants} workers became ready before the spawn timeout.",
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

            $byParticipant[$participantId] = ParticipantResult::workerFailure($plan->runId, $participantId, $message);
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
        $output = trim($process->errorOutput().' '.$process->output());
        $limit = max(0, ConfigValue::integer($this->config, 'raceproof.capture.worker_output_bytes', 4_096));

        if ($output === '' || $limit === 0 || strlen($output) <= $limit) {
            return $limit === 0 ? '' : $output;
        }

        $marker = ' [truncated]';

        if ($limit <= strlen($marker)) {
            return substr($output, 0, $limit);
        }

        return substr($output, 0, $limit - strlen($marker)).$marker;
    }
}
