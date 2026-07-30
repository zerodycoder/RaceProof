<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\CoordinationTimeout;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\RunId;

final class FileCoordinatorStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = dirname(__DIR__, 2).'/build/coordinator-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_coordinates_a_complete_run_with_atomic_files(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $plan = new RacePlan(
            RunId::generate(),
            2,
            new RequestSpec('POST', '/checkout', ['product_id' => 1]),
            checkpoints: ['after-read'],
        );

        $store->createRun($plan);
        self::assertDirectoryExists($this->basePath.'/'.$plan->runId.'/participants');
        self::assertDirectoryExists($this->basePath.'/'.$plan->runId.'/checkpoints');
        self::assertSame(
            json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            file_get_contents($this->basePath.'/'.$plan->runId.'/plan.json'),
        );
        self::assertEquals($plan, $store->plan($plan->runId));

        $store->markReady($plan->runId, 'p1');
        $store->markReady($plan->runId, 'p2');
        self::assertSame(2, $store->readyCount($plan->runId));

        $store->releaseStart($plan->runId);
        $store->waitForStart($plan->runId, 10);

        $store->reachCheckpoint($plan->runId, 'p1', 'after-read');
        $store->reachCheckpoint($plan->runId, 'p2', 'after-read');
        self::assertSame(2, $store->checkpointCount($plan->runId, 'after-read'));
        $store->releaseCheckpoint($plan->runId, 'after-read');
        $store->waitForCheckpoint($plan->runId, 'after-read', 10);

        $result = new ParticipantResult($plan->runId, 'p1', 201, 100, 200, '{"ok":true}');
        $store->storeResult($result);
        self::assertEquals([$result], $store->results($plan->runId));

        $timeline = $store->timeline($plan->runId);
        self::assertSame([
            'run.created',
            'participant.ready',
            'participant.ready',
            'barrier.start_released',
            'checkpoint.reached',
            'checkpoint.reached',
            'checkpoint.released',
            'participant.finished',
        ], array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $timeline->events,
        ));
        self::assertSame([
            [
                'type' => 'run.created',
                'participant_id' => null,
                'checkpoint' => null,
                'data' => [
                    'participants' => 2,
                    'checkpoint_count' => 1,
                    'participant_override_count' => 0,
                ],
            ],
            [
                'type' => 'participant.ready',
                'participant_id' => 'p1',
                'checkpoint' => null,
                'data' => [],
            ],
            [
                'type' => 'participant.ready',
                'participant_id' => 'p2',
                'checkpoint' => null,
                'data' => [],
            ],
            [
                'type' => 'barrier.start_released',
                'participant_id' => null,
                'checkpoint' => null,
                'data' => [],
            ],
            [
                'type' => 'checkpoint.reached',
                'participant_id' => 'p1',
                'checkpoint' => 'after-read',
                'data' => [],
            ],
            [
                'type' => 'checkpoint.reached',
                'participant_id' => 'p2',
                'checkpoint' => 'after-read',
                'data' => [],
            ],
            [
                'type' => 'checkpoint.released',
                'participant_id' => null,
                'checkpoint' => 'after-read',
                'data' => [],
            ],
            [
                'type' => 'participant.finished',
                'participant_id' => 'p1',
                'checkpoint' => null,
                'data' => [
                    'outcome' => 'response',
                    'status' => 201,
                    'duration_ms' => 0.0001,
                    'exception_class' => null,
                ],
            ],
        ], array_map(
            static fn (TimelineEvent $event): array => [
                'type' => $event->type,
                'participant_id' => $event->participantId,
                'checkpoint' => $event->checkpoint,
                'data' => $event->data,
            ],
            $timeline->events,
        ));
        self::assertSame(200, $timeline->events[7]->occurredAtNs);
        self::assertSame($this->basePath, $store->basePath());
        self::assertSame(
            implode('', array_map(
                static fn (TimelineEvent $event): string => json_encode(
                    $event,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
                $timeline->events,
            )),
            file_get_contents($this->basePath.'/'.$plan->runId.'/timeline.jsonl'),
        );

        $store->cleanup($plan->runId);
        self::assertDirectoryDoesNotExist($this->basePath.'/'.$plan->runId);
    }

    public function test_result_evidence_distinguishes_all_outcomes_and_sorts_participants(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $plan = new RacePlan(
            RunId::generate(),
            3,
            new RequestSpec('POST', '/checkout'),
        );
        $store->createRun($plan);
        $store->storeResult(new ParticipantResult(
            $plan->runId,
            'p3',
            null,
            30,
            60,
            workerError: 'worker failed',
        ));
        $store->storeResult(new ParticipantResult($plan->runId, 'p1', 201, 10, 20));
        $store->storeResult(new ParticipantResult(
            $plan->runId,
            'p2',
            null,
            20,
            40,
            exceptionClass: 'RuntimeException',
            exceptionMessage: 'application failed',
        ));

        $results = $store->results($plan->runId);
        $finished = $store->timeline($plan->runId)->ofType('participant.finished');

        self::assertSame(['p1', 'p2', 'p3'], array_map(
            static fn (ParticipantResult $result): string => $result->participantId,
            $results,
        ));
        self::assertSame([
            [
                'outcome' => 'worker_error',
                'status' => null,
                'duration_ms' => 0.00003,
                'exception_class' => null,
            ],
            [
                'outcome' => 'response',
                'status' => 201,
                'duration_ms' => 0.00001,
                'exception_class' => null,
            ],
            [
                'outcome' => 'exception',
                'status' => null,
                'duration_ms' => 0.00002,
                'exception_class' => 'RuntimeException',
            ],
        ], array_map(
            static fn (TimelineEvent $event): array => $event->data,
            $finished,
        ));
        self::assertSame([60, 20, 40], array_map(
            static fn (TimelineEvent $event): int => $event->occurredAtNs,
            $finished,
        ));
    }

    public function test_missing_malformed_and_cross_run_evidence_fails_closed(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $missingRunId = RunId::generate();

        try {
            $store->plan($missingRunId);
            self::fail('Expected the missing plan to be rejected.');
        } catch (RaceProofException $exception) {
            self::assertSame("Race plan [{$missingRunId}] does not exist.", $exception->getMessage());
        }

        self::assertSame(
            ['Timeline file is missing.'],
            $store->timeline($missingRunId)->warnings,
        );

        try {
            $store->recordEvent(TimelineEvent::make($missingRunId, 'run.note'));
            self::fail('Expected the event for a missing run to be rejected.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                "Cannot record timeline event for missing run [{$missingRunId}].",
                $exception->getMessage(),
            );
        }

        $plan = new RacePlan(
            RunId::generate(),
            2,
            new RequestSpec('POST', '/checkout'),
        );
        $store->createRun($plan);
        $valid = new ParticipantResult($plan->runId, 'p1', 200, 1, 2);
        $store->storeResult($valid);
        file_put_contents(
            $this->basePath.'/'.$plan->runId.'/participants/p2.result.json',
            '{"invalid":',
            LOCK_EX,
        );
        $foreignRunId = RunId::generate();
        file_put_contents(
            $this->basePath.'/'.$plan->runId.'/timeline.jsonl',
            "\n".json_encode(
                TimelineEvent::make($foreignRunId, 'run.note'),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )."\n",
            FILE_APPEND | LOCK_EX,
        );

        self::assertEquals([$valid], $store->results($plan->runId));
        $timeline = $store->timeline($plan->runId);
        self::assertSame(['run.created', 'participant.finished'], array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $timeline->events,
        ));
        self::assertSame(
            ['Timeline line 4 is malformed and was ignored.'],
            $timeline->warnings,
        );

        file_put_contents(
            $this->basePath.'/'.$plan->runId.'/plan.json',
            '{"invalid":',
            LOCK_EX,
        );

        try {
            $store->plan($plan->runId);
            self::fail('Expected invalid plan JSON to be rejected.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                "Race plan [{$plan->runId}] contains invalid JSON.",
                $exception->getMessage(),
            );
            self::assertSame(0, $exception->getCode());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function test_barrier_timeouts_and_identifier_validation_are_actionable(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $runId = RunId::generate();

        try {
            $store->waitForStart($runId, 0);
            self::fail('Expected a start barrier timeout.');
        } catch (CoordinationTimeout $exception) {
            self::assertSame(
                "Timed out waiting for the start barrier for run [{$runId}].",
                $exception->getMessage(),
            );
        }

        try {
            $store->waitForCheckpoint($runId, 'after-read', 0);
            self::fail('Expected a checkpoint timeout.');
        } catch (CoordinationTimeout $exception) {
            self::assertSame(
                "Timed out waiting for checkpoint [after-read] in run [{$runId}].",
                $exception->getMessage(),
            );
        }

        foreach ([
            static fn () => $store->readyCount('invalid'),
            static fn () => $store->markReady($runId, '../p1'),
            static fn () => $store->checkpointCount($runId, '../checkpoint'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected an invalid coordination identifier.');
            } catch (RaceProofException $exception) {
                self::assertStringStartsWith('Invalid ', $exception->getMessage());
            }
        }
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
