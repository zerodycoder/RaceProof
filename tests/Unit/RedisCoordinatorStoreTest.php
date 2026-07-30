<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\RedisCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\CoordinationTimeout;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Tests\Support\InMemoryRedisClient;
use RuntimeException;

final class RedisCoordinatorStoreTest extends TestCase
{
    public function test_it_coordinates_an_idempotent_run_with_ordered_evidence_and_cleanup(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $plan = $this->plan(str_repeat('a', 32));

        self::assertSame('redis', $store->driver());
        self::assertSame(
            'redis://default/raceproof-test/runs/'.$plan->runId,
            $store->artifactReference($plan->runId),
        );
        $store->healthCheck();
        $store->createRun($plan);
        self::assertEquals($plan, $store->plan($plan->runId));
        self::assertSame([$plan->runId], $store->retainedRunIds());
        $stateKey = "raceproof-test:run:{{$plan->runId}}";
        self::assertStringContainsString('"/checkout"', $client->hashes[$stateKey]['plan']);
        self::assertSame([
            'participants' => 2,
            'checkpoint_count' => 1,
            'participant_override_count' => 0,
        ], $store->timeline($plan->runId)->events[0]->data);

        $store->markReady($plan->runId, 'p2');
        $store->markReady($plan->runId, 'p1');
        $store->markReady($plan->runId, 'p1');
        self::assertSame(2, $store->readyCount($plan->runId));

        $store->releaseStart($plan->runId);
        $store->releaseStart($plan->runId);
        $store->waitForStart($plan->runId, 1);

        $store->reachCheckpoint($plan->runId, 'p2', 'after-read');
        $store->reachCheckpoint($plan->runId, 'p1', 'after-read');
        $store->reachCheckpoint($plan->runId, 'p1', 'after-read');
        self::assertSame(2, $store->checkpointCount($plan->runId, 'after-read'));
        $store->releaseCheckpoint($plan->runId, 'after-read');
        $store->releaseCheckpoint($plan->runId, 'after-read');
        $store->waitForCheckpoint($plan->runId, 'after-read', 1);

        $second = new ParticipantResult($plan->runId, 'p2', 409, 20, 40, 'second');
        $first = new ParticipantResult($plan->runId, 'p1', 201, 10, 30, 'first');
        $hgetCount = $this->commandCount($client, 'hget');
        $store->storeResult($second);
        $store->storeResult($first);
        self::assertSame($hgetCount, $this->commandCount($client, 'hget'));
        $store->storeResult($first);
        self::assertSame($hgetCount + 1, $this->commandCount($client, 'hget'));
        self::assertEquals([$first, $second], $store->results($plan->runId));

        $custom = TimelineEvent::make($plan->runId, 'run.custom');
        $store->recordEvent($custom);
        $store->recordEvent($custom);
        self::assertSame([
            'run.created',
            'participant.ready',
            'participant.ready',
            'barrier.start_released',
            'checkpoint.reached',
            'checkpoint.reached',
            'checkpoint.released',
            'participant.finished',
            'participant.finished',
            'run.custom',
        ], array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $store->timeline($plan->runId)->events,
        ));

        $store->cleanup($plan->runId);
        $store->cleanup($plan->runId);
        self::assertSame([], $store->retainedRunIds());
        self::assertSame(
            ['Timeline state is missing.'],
            $store->timeline($plan->runId)->warnings,
        );
    }

    public function test_run_creation_is_collision_safe(): void
    {
        $store = $this->store(new InMemoryRedisClient);
        $plan = $this->plan(str_repeat('a', 32));
        $store->createRun($plan);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('already exists');

        $store->createRun($plan);
    }

    public function test_a_conflicting_participant_result_is_rejected(): void
    {
        $store = $this->store(new InMemoryRedisClient);
        $plan = $this->plan(str_repeat('a', 32));
        $store->createRun($plan);
        $store->storeResult(new ParticipantResult($plan->runId, 'p1', 201, 10, 20, 'first'));

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('already stores a different result');

        $store->storeResult(new ParticipantResult($plan->runId, 'p1', 201, 10, 20, 'changed'));
    }

    public function test_missing_runs_and_barrier_timeouts_fail_closed(): void
    {
        $store = $this->store(new InMemoryRedisClient);
        $runId = str_repeat('a', 32);

        try {
            $store->markReady($runId, 'p1');
            self::fail('Expected a missing run transition to fail.');
        } catch (RaceProofException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }

        $store->createRun($this->plan($runId));

        try {
            $store->waitForStart($runId, 0);
            self::fail('Expected an unreleased start barrier to time out.');
        } catch (CoordinationTimeout $exception) {
            self::assertStringContainsString('start barrier', $exception->getMessage());
        }

        $this->expectException(CoordinationTimeout::class);
        $this->expectExceptionMessage('after-read');

        $store->waitForCheckpoint($runId, 'after-read', 0);
    }

    public function test_retained_run_discovery_prunes_expired_missing_and_foreign_entries(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $store->createRun($this->plan($second));
        $store->createRun($this->plan($first));
        unset($client->hashes["raceproof-test:run:{{$second}}"]);
        $client->injectIndex('raceproof-test:runs', 'foreign', time() + 60);
        $client->injectIndex('raceproof-test:runs', str_repeat('c', 32), time() - 1);

        self::assertSame([$first], $store->retainedRunIds());
        self::assertSame([$first], array_keys($client->indexes['raceproof-test:runs']));
    }

    public function test_reads_do_not_refresh_the_retention_index_but_writes_do(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $runId = str_repeat('a', 32);
        $store->createRun($this->plan($runId));
        $scriptCount = count($client->scripts);
        $expiresAt = $client->indexes['raceproof-test:runs'][$runId];

        $store->plan($runId);
        $store->readyCount($runId);
        $store->results($runId);
        $store->timeline($runId);

        self::assertSame($scriptCount, count($client->scripts));
        self::assertSame($expiresAt, $client->indexes['raceproof-test:runs'][$runId]);

        $store->markReady($runId, 'p1');

        self::assertSame($scriptCount + 1, count($client->scripts));
        self::assertGreaterThanOrEqual(
            $expiresAt,
            $client->indexes['raceproof-test:runs'][$runId],
        );
    }

    public function test_malformed_timeline_and_result_entries_are_bounded(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $runId = str_repeat('a', 32);
        $store->createRun($this->plan($runId));
        $key = "raceproof-test:run:{{$runId}}";
        $client->hashes[$key]['event:2'] = '{broken';
        $client->hashes[$key]['result:p1'] = '{broken';

        self::assertSame([], $store->results($runId));
        self::assertSame(
            ['Timeline event 2 is malformed and was ignored.'],
            $store->timeline($runId)->warnings,
        );
    }

    public function test_configuration_is_bounded_without_echoing_values(): void
    {
        $client = new InMemoryRedisClient;
        $secret = 'redis://user:super-secret@example.test';
        $boundary = new RedisCoordinatorStore(
            $client,
            'default',
            'raceproof',
            604_800,
            1_000,
        );

        self::assertSame('redis', $boundary->driver());

        foreach ([
            [$secret, 'raceproof', 60, 5],
            ['default', $secret, 60, 5],
            ['default', 'raceproof', 59, 5],
            ['default', 'raceproof', 604_801, 5],
            ['default', 'raceproof', 60, 0],
            ['default', 'raceproof', 60, 1_001],
        ] as [$connection, $namespace, $ttl, $poll]) {
            try {
                new RedisCoordinatorStore($client, $connection, $namespace, $ttl, $poll);
                self::fail('Expected Redis coordinator configuration to be rejected.');
            } catch (RaceProofException $exception) {
                self::assertStringNotContainsString($secret, $exception->getMessage());
                self::assertStringNotContainsString('super-secret', $exception->getMessage());
            }
        }
    }

    public function test_health_check_validates_client_responses_and_probe_shape(): void
    {
        foreach ([false, null] as $ping) {
            $client = new InMemoryRedisClient;
            $client->commandOverrides['ping'] = $ping;

            try {
                $this->store($client)->healthCheck();
                self::fail('Expected an invalid Redis ping response to fail.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'RaceProof Redis coordinator health check failed.',
                    $exception->getMessage(),
                );
                self::assertSame([], $client->evaluations);
            }
        }

        $client = new InMemoryRedisClient;
        $client->commandOverride = true;
        $this->store($client)->healthCheck();
        self::assertSame(['health'], $client->scripts);
        self::assertCount(1, $client->evaluations);
        $probe = $client->evaluations[0];
        self::assertSame('health', $probe['operation']);
        self::assertCount(1, $probe['keys']);
        self::assertMatchesRegularExpression(
            '/^raceproof-test:health:[a-f0-9]{16}$/D',
            $probe['keys'][0],
        );
        self::assertCount(2, $probe['arguments']);
        self::assertIsString($probe['arguments'][0]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $probe['arguments'][0]);
        self::assertSame(10, $probe['arguments'][1]);
    }

    public function test_retained_results_are_filtered_sorted_and_shape_checked(): void
    {
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $client = new InMemoryRedisClient;
        $client->evaluationOverride = [$second, 'foreign', $first];

        self::assertSame([$first, $second], $this->store($client)->retainedRunIds());

        foreach ([
            ['run' => $first],
            [$first, 123],
        ] as $invalid) {
            $client = new InMemoryRedisClient;
            $client->evaluationOverride = $invalid;

            try {
                $this->store($client)->retainedRunIds();
                self::fail('Expected malformed retained-run state to fail.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'RaceProof Redis coordinator returned invalid state.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_result_events_preserve_exact_outcome_projection(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $runId = str_repeat('a', 32);
        $store->createRun($this->plan($runId));
        $store->storeResult(new ParticipantResult($runId, 'p1', 201, 10, 30));
        $store->storeResult(new ParticipantResult(
            $runId,
            'p2',
            null,
            20,
            50,
            exceptionClass: RuntimeException::class,
            exceptionMessage: 'failed',
        ));
        $store->storeResult(new ParticipantResult(
            $runId,
            'p3',
            null,
            30,
            70,
            workerError: 'worker exited',
        ));
        $finished = array_values(array_filter(
            $store->timeline($runId)->events,
            static fn (TimelineEvent $event): bool => $event->type === 'participant.finished',
        ));

        self::assertSame([
            [
                'outcome' => 'response',
                'status' => 201,
                'duration_ms' => 0.00002,
                'exception_class' => null,
            ],
            [
                'outcome' => 'exception',
                'status' => null,
                'duration_ms' => 0.00003,
                'exception_class' => RuntimeException::class,
            ],
            [
                'outcome' => 'worker_error',
                'status' => null,
                'duration_ms' => 0.00004,
                'exception_class' => null,
            ],
        ], array_map(
            static fn (TimelineEvent $event): array => $event->data,
            $finished,
        ));
    }

    public function test_record_event_rejects_a_missing_run(): void
    {
        $store = $this->store(new InMemoryRedisClient);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('Cannot record timeline event for missing run');

        $store->recordEvent(TimelineEvent::make(str_repeat('a', 32), 'run.custom'));
    }

    public function test_timeline_events_are_sorted_by_numeric_sequence(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $runId = str_repeat('a', 32);
        $store->createRun($this->plan($runId));
        $key = "raceproof-test:run:{{$runId}}";
        $client->hashes[$key]['event:10'] = json_encode(
            TimelineEvent::make($runId, 'run.tenth'),
            JSON_THROW_ON_ERROR,
        );
        $client->hashes[$key]['event:2'] = json_encode(
            TimelineEvent::make($runId, 'run.second'),
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([
            'run.created',
            'run.second',
            'run.tenth',
        ], array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $store->timeline($runId)->events,
        ));
    }

    public function test_flat_hash_responses_are_normalized_and_malformed_shapes_fail_closed(): void
    {
        $runId = str_repeat('a', 32);
        $result = new ParticipantResult($runId, 'p1', 200, 10, 20);
        $client = new InMemoryRedisClient;
        $client->commandOverrides['hgetall'] = [
            'result:p1',
            json_encode($result, JSON_THROW_ON_ERROR),
        ];

        self::assertEquals([$result], $this->store($client)->results($runId));

        foreach ([
            ['result:p1'],
            ['result:p1', 123],
            ['result:p1' => 123],
        ] as $invalid) {
            $client = new InMemoryRedisClient;
            $client->commandOverrides['hgetall'] = $invalid;

            try {
                $this->store($client)->results($runId);
                self::fail('Expected malformed Redis hash state to fail.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'RaceProof Redis coordinator returned invalid state.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_false_and_null_hash_values_both_mean_missing(): void
    {
        foreach ([false, null] as $missing) {
            $client = new InMemoryRedisClient;
            $client->commandOverrides['hget'] = $missing;

            try {
                $this->store($client)->plan(str_repeat('a', 32));
                self::fail('Expected a missing plan.');
            } catch (RaceProofException $exception) {
                self::assertStringContainsString('does not exist', $exception->getMessage());
            }
        }
    }

    public function test_client_failures_are_generic_and_do_not_disclose_credentials(): void
    {
        $client = new InMemoryRedisClient;
        $secret = 'redis://user:super-secret@example.test';
        $client->failure = new RuntimeException("Unable to connect to {$secret}");
        $store = $this->store($client);

        try {
            $store->healthCheck();
            self::fail('Expected Redis health to fail.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof Redis coordinator is unavailable or misconfigured.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_invalid_client_results_fail_closed(): void
    {
        $client = new InMemoryRedisClient;
        $client->commandOverride = new \stdClass;
        $store = $this->store($client);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('returned invalid state');

        $store->results(str_repeat('a', 32));
    }

    public function test_phpredis_boolean_command_results_are_normalized(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);
        $client->commandOverride = true;

        $store->waitForStart(str_repeat('a', 32), 0);

        $client->commandOverride = false;

        $this->expectException(CoordinationTimeout::class);
        $store->waitForStart(str_repeat('a', 32), 0);
    }

    public function test_identifiers_are_validated_before_redis_commands(): void
    {
        $client = new InMemoryRedisClient;
        $store = $this->store($client);

        foreach ([
            fn () => $store->artifactReference('../run'),
            fn () => $store->markReady(str_repeat('a', 32), 'participant-1'),
            fn () => $store->checkpointCount(str_repeat('a', 32), '../checkpoint'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected an invalid identifier to be rejected.');
            } catch (RaceProofException) {
                self::assertSame([], $client->commands);
                self::assertSame([], $client->scripts);
            }
        }
    }

    private function store(InMemoryRedisClient $client): RedisCoordinatorStore
    {
        return new RedisCoordinatorStore($client, 'default', 'raceproof-test', 60, 1);
    }

    private function plan(string $runId): RacePlan
    {
        return new RacePlan(
            $runId,
            2,
            new RequestSpec('POST', '/checkout'),
            checkpoints: ['after-read'],
        );
    }

    private function commandCount(InMemoryRedisClient $client, string $command): int
    {
        return count(array_filter(
            $client->commands,
            static fn (array $record): bool => $record['command'] === $command,
        ));
    }
}
