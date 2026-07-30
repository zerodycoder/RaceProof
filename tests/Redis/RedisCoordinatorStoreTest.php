<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Redis;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\RedisCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Tests\Integration\TestCase;

final class RedisCoordinatorStoreTest extends TestCase
{
    private string $namespace;

    private ?RedisCoordinatorStore $store = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RACEPROOF_REDIS_TEST') !== '1') {
            $this->markTestSkipped('Real Redis evidence is enabled only in the dedicated Redis job.');
        }

        $this->namespace = 'raceproof-ci-'.bin2hex(random_bytes(6));
        $this->app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => ''],
            'default' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
                'database' => (int) (getenv('REDIS_DB') ?: 15),
                'read_timeout' => 2,
            ],
        ]);
        $this->app['config']->set('raceproof.coordinator.driver', 'redis');
        $this->app['config']->set('raceproof.coordinator.redis.connection', 'default');
        $this->app['config']->set('raceproof.coordinator.redis.namespace', $this->namespace);
        $this->app['config']->set('raceproof.coordinator.redis.ttl_seconds', 60);
        $this->app['config']->set('raceproof.coordinator.redis.poll_interval_ms', 1);
        $this->forgetRedisCoordinator();
        $store = $this->app->make(CoordinatorStore::class);
        self::assertInstanceOf(RedisCoordinatorStore::class, $store);
        $this->store = $store;
    }

    protected function tearDown(): void
    {
        if (isset($this->namespace) && $this->store !== null) {
            foreach ($this->store->retainedRunIds() as $runId) {
                $this->store->cleanup($runId);
            }

            $this->connection()->command('del', [$this->namespace.':runs']);
        }

        parent::tearDown();
    }

    public function test_real_redis_lifecycle_is_atomic_bounded_and_cleanup_safe(): void
    {
        $store = $this->requiredStore();
        $runId = bin2hex(random_bytes(16));
        $plan = new RacePlan(
            $runId,
            2,
            new RequestSpec('POST', '/redis-evidence'),
            checkpoints: ['after-read'],
        );

        $store->healthCheck();
        $store->createRun($plan);
        self::assertEquals($plan, $store->plan($runId));
        self::assertSame([$runId], $store->retainedRunIds());

        try {
            $store->createRun($plan);
            self::fail('Expected a Redis run collision to fail.');
        } catch (RaceProofException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        $store->markReady($runId, 'p1');
        $store->markReady($runId, 'p1');
        $store->markReady($runId, 'p2');
        self::assertSame(2, $store->readyCount($runId));
        $store->releaseStart($runId);
        $store->releaseStart($runId);
        $store->waitForStart($runId, 50);

        $store->reachCheckpoint($runId, 'p1', 'after-read');
        $store->reachCheckpoint($runId, 'p2', 'after-read');
        self::assertSame(2, $store->checkpointCount($runId, 'after-read'));
        $store->releaseCheckpoint($runId, 'after-read');
        $store->waitForCheckpoint($runId, 'after-read', 50);

        $second = new ParticipantResult($runId, 'p2', 409, 20, 40);
        $first = new ParticipantResult($runId, 'p1', 201, 10, 30);
        $store->storeResult($second);
        $store->storeResult($first);
        $store->storeResult($first);
        self::assertEquals([$first, $second], $store->results($runId));

        $stateKey = $this->namespace.":run:{{$runId}}";
        $ttlBeforeRead = $this->connection()->command('ttl', [$stateKey]);
        self::assertIsInt($ttlBeforeRead);
        self::assertGreaterThan(0, $ttlBeforeRead);
        self::assertLessThanOrEqual(60, $ttlBeforeRead);
        $store->plan($runId);
        $ttlAfterRead = $this->connection()->command('ttl', [$stateKey]);
        self::assertIsInt($ttlAfterRead);
        self::assertLessThanOrEqual($ttlBeforeRead, $ttlAfterRead);

        $types = array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $store->timeline($runId)->events,
        );
        self::assertSame(9, count($types));
        self::assertSame('run.created', $types[0]);
        self::assertSame(2, count(array_filter(
            $types,
            static fn (string $type): bool => $type === 'participant.ready',
        )));
        self::assertSame(2, count(array_filter(
            $types,
            static fn (string $type): bool => $type === 'participant.finished',
        )));

        $expired = str_repeat('f', 32);
        $this->connection()->command(
            'zadd',
            [$this->namespace.':runs', time() - 1, $expired],
        );
        self::assertSame([$runId], $store->retainedRunIds());

        $store->cleanup($runId);
        $store->cleanup($runId);
        self::assertSame([], $store->retainedRunIds());
        self::assertSame(0, $this->connection()->command('exists', [$stateKey]));

        $this->writeEvidence([
            'schema_version' => 1,
            'driver' => 'redis',
            'run_id' => $runId,
            'ttl_seconds' => 60,
            'atomic_collision' => true,
            'idempotent_transitions' => true,
            'ordered_event_count' => count($types),
            'cleanup_verified' => true,
        ]);
    }

    private function requiredStore(): RedisCoordinatorStore
    {
        if ($this->store === null) {
            self::fail('Redis coordinator was not initialized.');
        }

        return $this->store;
    }

    private function connection(): Connection
    {
        return $this->app->make(RedisFactory::class)->connection('default');
    }

    /** @param array<string, bool|int|string> $evidence */
    private function writeEvidence(array $evidence): void
    {
        $path = getenv('RACEPROOF_REDIS_EVIDENCE_OUTPUT');

        if (! is_string($path) || $path === '') {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents(
            $path,
            json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
            LOCK_EX,
        );
    }

    private function forgetRedisCoordinator(): void
    {
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance(RedisFactory::class);
        $this->app->forgetInstance(RedisCoordinatorStore::class);
        $this->app->forgetInstance(CoordinatorResolver::class);
        $this->app->forgetInstance(CoordinatorStore::class);
    }
}
