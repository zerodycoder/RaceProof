<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\ParticipantSpec;
use RaceProof\Laravel\Data\QueueSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Exceptions\RaceProofException;

final class QueueSpecTest extends TestCase
{
    public function test_it_round_trips_a_bounded_participant_queue_manifest(): void
    {
        $spec = new QueueSpec(
            connection: 'raceproof-redis',
            maxAttempts: 3,
            backoffSeconds: 2,
            jobClasses: [
                'p1' => QueueSpecFirstJob::class,
                'p2' => QueueSpecSecondJob::class,
            ],
        );
        $plan = new RacePlan(
            runId: str_repeat('a', 32),
            participants: 2,
            request: null,
            queue: $spec,
        );

        self::assertEquals($plan, RacePlan::fromArray(json_decode(
            json_encode($plan, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )));
        self::assertSame('raceproof:'.str_repeat('a', 32).':p2', $spec->queueFor($plan->runId, 'p2'));
        self::assertSame(QueueSpecFirstJob::class, $spec->jobClassFor('p1'));
        self::assertSame('raceproof_database', $spec->withConnection('raceproof_database')->connection);
    }

    public function test_a_plan_requires_exactly_one_complete_workload(): void
    {
        $request = new RequestSpec('POST', '/checkout');
        $queue = new QueueSpec('database', jobClasses: [
            'p1' => QueueSpecFirstJob::class,
            'p2' => QueueSpecSecondJob::class,
        ]);

        foreach ([
            static fn () => new RacePlan(str_repeat('a', 32), 2, null),
            static fn () => new RacePlan(str_repeat('a', 32), 2, $request, queue: $queue),
            static fn () => new RacePlan(
                str_repeat('a', 32),
                2,
                null,
                participantSpecs: ['p1' => new ParticipantSpec(payload: ['unsafe' => true])],
                queue: $queue,
            ),
            static fn () => new RacePlan(
                str_repeat('a', 32),
                3,
                null,
                queue: $queue,
            ),
        ] as $invalidPlan) {
            try {
                $invalidPlan();
                self::fail('Expected the invalid queue plan to be rejected.');
            } catch (InvalidRacePlan) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_queue_participant_results_require_exact_bounded_metadata(): void
    {
        $runId = str_repeat('a', 32);
        $valid = new ParticipantResult(
            runId: $runId,
            participantId: 'p1',
            status: 204,
            startedAtNs: 1,
            finishedAtNs: 2,
            execution: 'queue',
            attempts: 2,
            jobClass: QueueSpecFirstJob::class,
            queueConnection: 'raceproof_database',
            queueName: "raceproof:{$runId}:p1",
        );

        self::assertSame($valid->jsonSerialize(), ParticipantResult::fromArray($valid->jsonSerialize())->jsonSerialize());

        foreach ([
            ['attempts' => 6],
            ['jobClass' => 'App\\Jobs\\Job payload=secret'],
            ['queueConnection' => 'invalid connection'],
            ['queueName' => "raceproof:{$runId}:p2"],
        ] as $override) {
            try {
                new ParticipantResult(
                    runId: $runId,
                    participantId: 'p1',
                    status: 204,
                    startedAtNs: 1,
                    finishedAtNs: 2,
                    execution: 'queue',
                    attempts: $override['attempts'] ?? 1,
                    jobClass: $override['jobClass'] ?? QueueSpecFirstJob::class,
                    queueConnection: $override['queueConnection'] ?? 'raceproof_database',
                    queueName: $override['queueName'] ?? "raceproof:{$runId}:p1",
                );
                self::fail('Expected invalid queue participant metadata to be rejected.');
            } catch (RaceProofException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidSpecs')]
    public function test_it_rejects_unbounded_or_malformed_queue_specs(callable $factory): void
    {
        $this->expectException(InvalidRacePlan::class);

        $factory();
    }

    /** @return iterable<string, array{callable(): QueueSpec}> */
    public static function invalidSpecs(): iterable
    {
        yield 'connection' => [static fn () => new QueueSpec('bad connection')];
        yield 'attempt lower bound' => [static fn () => new QueueSpec('database', 0)];
        yield 'attempt upper bound' => [static fn () => new QueueSpec('database', 6)];
        yield 'backoff lower bound' => [static fn () => new QueueSpec('database', 1, -1)];
        yield 'backoff upper bound' => [static fn () => new QueueSpec('database', 1, 61)];
        yield 'participant key' => [static fn () => new QueueSpec(
            'database',
            jobClasses: ['participant-1' => QueueSpecFirstJob::class],
        )];
        yield 'job class' => [static fn () => new QueueSpec(
            'database',
            jobClasses: ['p1' => 'not a class name!'],
        )];
    }
}

final class QueueSpecFirstJob {}

final class QueueSpecSecondJob {}
