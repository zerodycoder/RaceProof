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
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceExecutionFailed;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RaceProof\Laravel\Results\RaceTimeline;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
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
        self::assertSame([
            'participant.spawned',
            'participant.spawned',
            'participant.exited',
            'participant.exited',
            'run.completed',
            'run.cleanup_started',
        ], $this->eventTypes($result->timeline));
    }

    public function test_a_timeline_warning_retains_an_otherwise_clean_run(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->timelineWarnings = ['Timeline line 2 is malformed and was ignored.'];
        $store->storedResults = [
            $this->participant($plan, 'p1', 200),
            $this->participant($plan, 'p2', 200),
        ];
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: false),
            'p2' => new LifecycleWorkerProcess(running: false),
        ];

        $result = $this->orchestrator(
            $store,
            new LifecycleProcessFactory($processes),
            new LifecycleClock([0]),
        )->run($plan);

        self::assertSame('/raceproof-artifacts/'.$plan->runId, $result->artifactPath);
        self::assertSame([], $store->cleanedRuns);
        self::assertSame($store->timelineWarnings, $result->timeline?->warnings);
        self::assertNotContains('run.cleanup_started', $this->eventTypes($result->timeline));
    }

    public function test_checkpoint_is_not_released_before_the_complete_cohort_arrives(): void
    {
        $plan = $this->plan(checkpoints: ['after-read'], runTimeoutMs: 1);
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->checkpointReached = 1;
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: true, exitAfterStop: 143),
            'p2' => new LifecycleWorkerProcess(running: true, exitAfterStop: 143),
        ];

        $result = $this->orchestrator(
            $store,
            new LifecycleProcessFactory($processes),
            new LifecycleClock([0, 0, 1_000_000]),
        )->run($plan);

        self::assertTrue($result->timedOut);
        self::assertSame([], $store->releasedCheckpoints);
    }

    public function test_completed_run_is_archived_before_successful_scratch_evidence_is_cleaned(): void
    {
        $archivePath = dirname(__DIR__, 2).'/build/studio-orchestrator-tests/'.bin2hex(random_bytes(8));
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->storedResults = [
            $this->participant($plan, 'p1', 200),
            $this->participant($plan, 'p2', 200),
        ];
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: false),
            'p2' => new LifecycleWorkerProcess(running: false),
        ];
        $this->app['config']->set('raceproof.studio.enabled', true);
        $this->app['config']->set('raceproof.studio.path', $archivePath);
        $this->app->forgetInstance(ReportArchive::class);

        try {
            $result = $this->orchestrator(
                $store,
                new LifecycleProcessFactory($processes),
                new LifecycleClock([0]),
            )->run($plan);
            $reportPath = $archivePath.'/'.$plan->runId.'.json';
            $report = file_get_contents($reportPath);

            self::assertNull($result->artifactPath);
            self::assertSame([$plan->runId], $store->cleanedRuns);
            self::assertFileExists($reportPath);
            self::assertIsString($report);
            self::assertStringContainsString('"outcome": "passed"', $report);
            self::assertStringContainsString('"run.cleanup_started"', $report);
        } finally {
            $this->removeDirectory($archivePath);
            $this->app['config']->set('raceproof.studio.enabled', false);
            $this->app->forgetInstance(ReportArchive::class);
        }
    }

    public function test_early_exit_is_bounded_and_all_other_workers_are_stopped_and_waited(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $first = new LifecycleWorkerProcess(
            running: false,
            exitCode: 7,
            output: str_repeat('o', 40),
            errorOutput: 'Authorization: Bearer secret-token',
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
        } catch (RaceExecutionFailed $exception) {
            $expectedOutput = $this->app->make(SensitiveDataRedactor::class)->workerOutput(
                'Authorization: Bearer secret-token',
                str_repeat('o', 40),
            );
            self::assertSame(
                "Worker [p1] exited before the start barrier with exit code 7: {$expectedOutput}",
                $exception->getPrevious()?->getMessage(),
            );
            self::assertStringContainsString('exited before the start barrier with exit code 7', $exception->getMessage());
            self::assertStringContainsString('[truncated]', $exception->getMessage());
            self::assertStringNotContainsString(str_repeat('o', 40), $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            $earlyExit = $exception->result->timeline?->ofType('participant.early_exit') ?? [];
            self::assertCount(1, $earlyExit);
            self::assertSame('p1', $earlyExit[0]->participantId);
            self::assertSame(7, $earlyExit[0]->data['exit_code']);
            self::assertArrayHasKey('output', $earlyExit[0]->data);
            self::assertStringContainsString('[truncated]', (string) $earlyExit[0]->data['output']);
            self::assertStringNotContainsString('secret-token', (string) $earlyExit[0]->data['output']);
            self::assertSame([
                'participant.spawned',
                'participant.spawned',
                'participant.early_exit',
                'participant.exited',
                'participant.exited',
                'run.failed',
            ], $this->eventTypes($exception->result->timeline));
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
        $clock = new LifecycleClock([0, 1_000_000]);

        try {
            $this->orchestrator($store, new LifecycleProcessFactory($processes), $clock)->run($plan);
            self::fail('Expected the spawn timeout.');
        } catch (RaceExecutionFailed $exception) {
            self::assertInstanceOf(RaceProofException::class, $exception->getPrevious());
            self::assertStringContainsString('spawn timeout', $exception->getMessage());
            $timeouts = $exception->result->timeline?->ofType('run.spawn_timed_out') ?? [];
            self::assertCount(1, $timeouts);
            self::assertSame([
                'ready_count' => 0,
                'expected_count' => 2,
                'timeout_ms' => 1,
            ], $timeouts[0]->data);
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
            new LifecycleClock([0, 0, 1_000_000]),
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
        $timeouts = $result->timeline?->ofType('run.timed_out') ?? [];
        self::assertCount(1, $timeouts);
        self::assertSame(['timeout_ms' => 1], $timeouts[0]->data);
        self::assertSame([
            'participant.spawned',
            'participant.spawned',
            'run.timed_out',
            'participant.exited',
            'participant.exited',
            'run.completed',
        ], $this->eventTypes($result->timeline));
    }

    public function test_missing_result_is_distinct_from_timeout_and_retains_artifacts(): void
    {
        $plan = $this->plan();
        $store = new LifecycleCoordinatorStore;
        $store->ready = 2;
        $store->storedResults = [$this->participant($plan, 'p1', 201)];
        $processes = [
            'p1' => new LifecycleWorkerProcess(running: false, exitCode: 0),
            'p2' => new LifecycleWorkerProcess(running: false, exitCode: 5, output: 'worker stdout'),
        ];

        $result = $this->orchestrator(
            $store,
            new LifecycleProcessFactory($processes),
            new LifecycleClock([0]),
        )->run($plan);

        self::assertFalse($result->timedOut);
        self::assertSame(
            'Worker exited without a result with exit code 5. worker stdout',
            $result->participant('p2')?->workerError,
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

        try {
            $this->orchestrator($store, $factory, new LifecycleClock([0]))->run($plan);
            self::fail('Expected a wrapped execution failure.');
        } catch (RaceExecutionFailed $exception) {
            self::assertStringContainsString('factory failed for p2', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame('/raceproof-artifacts/'.$plan->runId, $exception->result->artifactPath);
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
            $this->app->make(SensitiveDataRedactor::class),
            $this->app->make(ReportArchive::class),
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

    /** @return list<string> */
    private function eventTypes(?RaceTimeline $timeline): array
    {
        self::assertNotNull($timeline);

        return array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $timeline->events,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
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

    /** @var list<TimelineEvent> */
    public array $events = [];

    /** @var list<string> */
    public array $timelineWarnings = [];

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

    public function recordEvent(TimelineEvent $event): void
    {
        $this->events[] = $event;
    }

    public function timeline(string $runId): RaceTimeline
    {
        return new RaceTimeline($runId, $this->events, $this->timelineWarnings);
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
