<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RuntimeException;

final class RaceOrchestratorLifecycleTest extends TestCase
{
    public function test_clean_workers_are_waited_reaped_and_then_cleaned_up(): void
    {
        $plan = $this->plan(checkpoints: ['after-read']);
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->checkpointReached = 2;
        $store->storedResults = [
            $this->participant($plan, 'p1', 200),
            $this->participant($plan, 'p2', 200),
        ];
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: false),
            'p2' => new LifecycleWorkerProcess(running: false),
        ];

        $result = $this->orchestrator($store, new LifecycleProcessFactory($processes), new LifecycleClock([0]))
            ->run($plan);

        self::assertNull($result->artifactPath);
        self::assertSame([$plan->runId], $store->cleanedRuns);
        self::assertSame(['after-read'], $store->releasedCheckpoints);
        self::assertSame(1, $processes['p1']->waitCalls);
        self::assertSame(1, $processes['p2']->waitCalls);
        self::assertSame(0, $processes['p1']->stopCalls);
    }

    public function test_early_exit_is_bounded_and_all_other_workers_are_stopped_and_waited(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $first = new LifecycleWorkerProcess(
            running: false,
            exitCode: 7,
            output: str_repeat('o', 40),
            errorOutput: 'worker-error',
        );
        $second = new LifecycleWorkerProcess(running: true);
        $this->app['config']->set('raceproof.capture.worker_output_bytes', 32);

        try {
            $this->orchestrator(
                $store,
                new LifecycleProcessFactory(['p1' => $first, 'p2' => $second]),
                new LifecycleClock([0]),
            )->run($plan);
            self::fail('Expected an early worker exit.');
        } catch (RaceProofException $exception) {
            self::assertStringContainsString('exited before the start barrier with exit code 7', $exception->getMessage());
            self::assertStringContainsString('[truncated]', $exception->getMessage());
            self::assertStringNotContainsString(str_repeat('o', 40), $exception->getMessage());
        }

        self::assertSame(1, $first->waitCalls);
        self::assertSame(1, $second->stopCalls);
        self::assertSame(1, $second->waitCalls);
    }

    public function test_spawn_timeout_uses_the_injected_clock_without_real_sleep(): void
    {
        $plan = $this->plan(spawnTimeoutMs: 1);
        $store = new LifecycleCoordinatorStore;
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: true),
            'p2' => new LifecycleWorkerProcess(running: true),
        ];
        $clock = new LifecycleClock([0, 2_000_000]);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('spawn timeout');

        try {
            $this->orchestrator($store, new LifecycleProcessFactory($processes), $clock)->run($plan);
        } finally {
            self::assertSame([], $clock->sleeps);
            self::assertSame(1, $processes['p1']->stopCalls);
            self::assertSame(1, $processes['p2']->waitCalls);
        }
    }

    public function test_run_timeout_stops_and_reaps_workers_and_returns_structured_failures(): void
    {
        $plan = $this->plan(runTimeoutMs: 1);
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: true, exitAfterStop: 143),
            'p2' => new LifecycleWorkerProcess(running: true, exitAfterStop: 143),
        ];

        $result = $this->orchestrator(
            $store,
            new LifecycleProcessFactory($processes),
            new LifecycleClock([0, 0, 2_000_000]),
        )->run($plan);

        self::assertTrue($result->timedOut);
        self::assertSame('/raceproof-artifacts/'.$plan->runId, $result->artifactPath);
        self::assertCount(2, $result->participants);
        self::assertStringContainsString(
            'terminated after the run timeout with exit code 143',
            (string) $result->participant('p1')?->workerError,
        );
        self::assertSame(1, $processes['p1']->stopCalls);
        self::assertSame(1, $processes['p1']->waitCalls);
    }

    public function test_missing_result_is_distinct_from_timeout_and_retains_artifacts(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->storedResults = [$this->participant($plan, 'p1', 201)];
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: false, exitCode: 0),
            'p2' => new LifecycleWorkerProcess(running: false, exitCode: 5),
        ];

        $result = $this->orchestrator(
            $store,
            new LifecycleProcessFactory($processes),
            new LifecycleClock([0]),
        )->run($plan);

        self::assertFalse($result->timedOut);
        self::assertStringContainsString(
            'exited without a result with exit code 5',
            (string) $result->participant('p2')?->workerError,
        );
        self::assertSame('/raceproof-artifacts/'.$plan->runId, $result->artifactPath);
        self::assertSame([], $store->cleanedRuns);
    }

    public function test_factory_failure_still_stops_and_waits_already_started_workers(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $first = new LifecycleWorkerProcess(running: true);
        $factory = new LifecycleProcessFactory(['p1' => $first], failParticipant: 'p2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('factory failed for p2');

        try {
            $this->orchestrator($store, $factory, new LifecycleClock([0]))->run($plan);
        } finally {
            self::assertSame(1, $first->stopCalls);
            self::assertSame(1, $first->waitCalls);
        }
    }

    private function orchestrator(
        CoordinatorStore $store,
        WorkerProcessFactory $factory,
        RaceClock $clock,
    ): RaceOrchestrator {
        return new RaceOrchestrator(
            $this->app['config'],
            $store,
            $this->app->make(EnvironmentGuard::class),
            $this->app->make(DatabaseSafety::class),
            $factory,
            $clock,
        );
    }

    /** @param list<string> $checkpoints */
    private function plan(
        array $checkpoints = [],
        int $spawnTimeoutMs = 10,
        int $runTimeoutMs = 10,
    ): RacePlan {
        return new RacePlan(
            runId: str_repeat('a', 32),
            participants: 2,
            request: new RequestSpec('POST', '/checkout'),
            checkpoints: $checkpoints,
            spawnTimeoutMs: $spawnTimeoutMs,
            runTimeoutMs: $runTimeoutMs,
            pollIntervalMs: 1,
        );
    }

    private function participant(RacePlan $plan, string $participantId, int $status): ParticipantResult
    {
        return new ParticipantResult($plan->runId, $participantId, $status, 1, 2);
    }
}

final class LifecycleCoordinatorStore implements CoordinatorStore
{
    public int $ready = 0;

    public int $checkpointReached = 0;

    /** @var list<ParticipantResult> */
    public array $storedResults = [];

    /** @var list<string> */
    public array $cleanedRuns = [];

    /** @var list<string> */
    public array $releasedCheckpoints = [];

    public ?RacePlan $createdPlan = null;

    public function createRun(RacePlan $plan): void
    {
        $this->createdPlan = $plan;
    }

    public function plan(string $runId): RacePlan
    {
        return $this->createdPlan ?? throw new RuntimeException('No plan was created.');
    }

    public function markReady(string $runId, string $participantId): void {}

    public function readyCount(string $runId): int
    {
        return $this->ready;
    }

    public function releaseStart(string $runId): void {}

    public function waitForStart(string $runId, int $timeoutMs): void {}

    public function reachCheckpoint(string $runId, string $participantId, string $checkpoint): void {}

    public function checkpointCount(string $runId, string $checkpoint): int
    {
        return $this->checkpointReached;
    }

    public function releaseCheckpoint(string $runId, string $checkpoint): void
    {
        $this->releasedCheckpoints[] = $checkpoint;
    }

    public function waitForCheckpoint(string $runId, string $checkpoint, int $timeoutMs): void {}

    public function storeResult(ParticipantResult $result): void
    {
        $this->storedResults[] = $result;
    }

    public function results(string $runId): array
    {
        return $this->storedResults;
    }

    public function cleanup(string $runId): void
    {
        $this->cleanedRuns[] = $runId;
    }

    public function basePath(): string
    {
        return '/raceproof-artifacts';
    }
}

final class LifecycleProcessFactory implements WorkerProcessFactory
{
    /** @param array<string, LifecycleWorkerProcess> $processes */
    public function __construct(
        private readonly array $processes,
        private readonly ?string $failParticipant = null,
    ) {}

    public function create(string $runId, string $participantId): WorkerProcess
    {
        if ($participantId === $this->failParticipant) {
            throw new RuntimeException("factory failed for {$participantId}");
        }

        return $this->processes[$participantId] ?? throw new RuntimeException("No fake process for {$participantId}");
    }
}

final class LifecycleWorkerProcess implements WorkerProcess
{
    public int $startCalls = 0;

    public int $stopCalls = 0;

    public int $waitCalls = 0;

    public function __construct(
        private bool $running,
        private int $exitCode = 0,
        private readonly string $output = '',
        private readonly string $errorOutput = '',
        private readonly ?int $exitAfterStop = null,
    ) {}

    public function start(): void
    {
        $this->startCalls++;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stop(float $timeoutSeconds): void
    {
        $this->stopCalls++;
        $this->running = false;
        $this->exitCode = $this->exitAfterStop ?? $this->exitCode;
    }

    public function wait(): int
    {
        $this->waitCalls++;
        $this->running = false;

        return $this->exitCode;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }
}

final class LifecycleClock implements RaceClock
{
    /** @var list<int> */
    private array $times;

    /** @var list<int> */
    public array $sleeps = [];

    /** @param list<int> $times */
    public function __construct(array $times)
    {
        $this->times = $times;
    }

    public function nowNs(): int
    {
        if (count($this->times) > 1) {
            return (int) array_shift($this->times);
        }

        return $this->times[0] ?? 0;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
