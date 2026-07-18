<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class RaceOrchestrator
{
    public function __construct(
        private Application $app,
        private Config $config,
        private FileCoordinatorStore $store,
        private EnvironmentGuard $environment,
        private DatabaseSafety $databaseSafety,
    ) {}

    public function run(RacePlan $plan): RaceResult
    {
        $this->environment->ensureEnabled();
        $this->databaseSafety->validate();
        $this->store->createRun($plan);

        /** @var array<string, Process> $processes */
        $processes = [];
        $timedOut = false;

        try {
            for ($number = 1; $number <= $plan->participants; $number++) {
                $participantId = 'p'.$number;
                $process = $this->workerProcess($plan->runId, $participantId);
                $process->start();
                $processes[$participantId] = $process;
            }

            $this->waitUntilReady($plan, $processes);
            $this->store->releaseStart($plan->runId);

            $deadline = Clock::nowNs() + ($plan->runTimeoutMs * 1_000_000);
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

                if (Clock::nowNs() >= $deadline) {
                    $timedOut = true;
                    $this->stopAll($processes);
                    break;
                }

                usleep($plan->pollIntervalMs * 1_000);
            }

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
            $this->stopAll($processes);
            throw $exception;
        }
    }

    /** @param array<string, Process> $processes */
    private function waitUntilReady(RacePlan $plan, array $processes): void
    {
        $deadline = Clock::nowNs() + ($plan->spawnTimeoutMs * 1_000_000);

        while ($this->store->readyCount($plan->runId) < $plan->participants) {
            foreach ($processes as $participantId => $process) {
                if (! $process->isRunning()) {
                    $error = trim($process->getErrorOutput().' '.$process->getOutput());
                    throw new RaceProofException(
                        "Worker [{$participantId}] exited before the start barrier".($error === '' ? '.' : ": {$error}"),
                    );
                }
            }

            if (Clock::nowNs() >= $deadline) {
                throw new RaceProofException(
                    "Only {$this->store->readyCount($plan->runId)}/{$plan->participants} workers became ready before timeout.",
                );
            }

            usleep($plan->pollIntervalMs * 1_000);
        }
    }

    private function workerProcess(string $runId, string $participantId): Process
    {
        $artisan = $this->app->basePath('artisan');

        if (! is_file($artisan)) {
            throw new RaceProofException("Laravel artisan file was not found at [{$artisan}].");
        }

        return new Process([
            PHP_BINARY,
            $artisan,
            'raceproof:worker',
            '--run='.$runId,
            '--participant='.$participantId,
            '--coordinator='.$this->store->basePath(),
            '--no-interaction',
        ], $this->app->basePath(), timeout: null);
    }

    /** @param array<string, Process> $processes */
    private function allStopped(array $processes): bool
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, Process> $processes */
    private function stopAll(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.5);
            }
        }
    }

    /**
     * @param  array<string, Process>  $processes
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

            $output = trim($process->getErrorOutput().' '.$process->getOutput());
            $message = $timedOut ? 'Worker was terminated after the run timeout.' : 'Worker exited without a result.';

            if ($output !== '') {
                $message .= ' '.$output;
            }

            $byParticipant[$participantId] = ParticipantResult::workerFailure($plan->runId, $participantId, $message);
        }

        ksort($byParticipant, SORT_NATURAL);

        return array_values($byParticipant);
    }
}
