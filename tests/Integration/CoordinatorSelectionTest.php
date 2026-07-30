<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Coordination\RedisCoordinatorStore;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RaceProof\Laravel\Execution\WorkerTransportResolver;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Remote\RemoteWorkerProcessFactory;
use RuntimeException;

final class CoordinatorSelectionTest extends TestCase
{
    public function test_parent_resolves_the_configured_file_backend(): void
    {
        $path = dirname(__DIR__, 2).'/build/coordinator-selection/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.coordinator.driver', 'file');
        $this->app['config']->set('raceproof.coordinator.path', $path);
        $this->forgetCoordinator();

        $store = $this->app->make(CoordinatorStore::class);

        self::assertInstanceOf(FileCoordinatorStore::class, $store);
        self::assertSame($path.'/'.str_repeat('a', 32), $store->artifactReference(str_repeat('a', 32)));
    }

    public function test_unsupported_driver_fails_before_worker_factory_resolution(): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';
        $factoryResolved = false;
        $this->app['config']->set('raceproof.coordinator.driver', $secret);
        $this->forgetCoordinator();
        $this->app->bind(WorkerProcessFactory::class, function () use (&$factoryResolved): never {
            $factoryResolved = true;

            throw new RuntimeException('Worker factory must not resolve.');
        });

        try {
            $this->app->make(RaceOrchestrator::class);
            self::fail('Expected coordinator selection to fail before orchestration.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof coordinator driver configuration is unsupported.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }

        self::assertFalse($factoryResolved);
    }

    public function test_parent_resolves_the_configured_redis_backend_without_connecting(): void
    {
        $this->app->instance(RedisFactory::class, new UnusedRedisFactory);
        $this->app['config']->set('raceproof.coordinator.driver', 'redis');
        $this->app['config']->set('raceproof.coordinator.redis.connection', 'raceproof');
        $this->app['config']->set('raceproof.coordinator.redis.namespace', 'tenant-a');
        $this->app['config']->set('raceproof.coordinator.redis.ttl_seconds', 300);
        $this->forgetCoordinator();

        $store = $this->app->make(CoordinatorStore::class);

        self::assertInstanceOf(RedisCoordinatorStore::class, $store);
        self::assertSame(
            'redis://raceproof/tenant-a/runs/'.str_repeat('a', 32),
            $store->artifactReference(str_repeat('a', 32)),
        );
    }

    public function test_malformed_redis_configuration_fails_before_worker_factory_resolution(): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';
        $factoryResolved = false;
        $this->app->instance(RedisFactory::class, new UnusedRedisFactory);
        $this->app['config']->set('raceproof.coordinator.driver', 'redis');
        $this->app['config']->set('raceproof.coordinator.redis.namespace', $secret);
        $this->forgetCoordinator();
        $this->app->bind(WorkerProcessFactory::class, function () use (&$factoryResolved): never {
            $factoryResolved = true;

            throw new RuntimeException('Worker factory must not resolve.');
        });

        try {
            $this->app->make(RaceOrchestrator::class);
            self::fail('Expected Redis configuration to fail before orchestration.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof Redis namespace configuration is invalid.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }

        self::assertFalse($factoryResolved);
    }

    public function test_local_worker_transport_remains_the_default(): void
    {
        $this->forgetTransport();

        $factory = $this->app->make(WorkerProcessFactory::class);

        self::assertInstanceOf(WorkerTransportResolver::class, $factory);
        self::assertSame('local', $factory->driver());
    }

    public function test_remote_worker_transport_rejects_the_file_coordinator_before_run_creation(): void
    {
        $this->app['config']->set('raceproof.worker_transport.driver', 'remote');
        $this->app['config']->set('raceproof.coordinator.driver', 'file');
        $this->forgetCoordinator();
        $this->forgetTransport();

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('requires the Redis coordinator');

        $this->app->make(RaceOrchestrator::class);
    }

    public function test_unknown_worker_transport_fails_without_exposing_configuration(): void
    {
        $secret = 'https://user:super-secret@example.test';
        $this->app['config']->set('raceproof.worker_transport.driver', $secret);
        $this->forgetTransport();

        try {
            $this->app->make(RaceOrchestrator::class);
            self::fail('Expected unknown worker transport to fail.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof worker transport configuration is unsupported.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }

    public function test_remote_configuration_is_validated_before_orchestrator_construction(): void
    {
        $this->app->instance(RedisFactory::class, new UnusedRedisFactory);
        $this->app['config']->set('raceproof.coordinator.driver', 'redis');
        $this->app['config']->set('raceproof.worker_transport.driver', 'remote');
        $this->app['config']->set('raceproof.worker_transport.remote.agents', ['agent-a']);
        $this->app['config']->set('raceproof.worker_transport.remote.secret', 'short');
        $this->forgetCoordinator();
        $this->forgetTransport();

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('authentication secret configuration is invalid');

        $this->app->make(RaceOrchestrator::class);
    }

    private function forgetCoordinator(): void
    {
        $this->app->forgetInstance(FileCoordinatorStore::class);
        $this->app->forgetInstance(RedisCoordinatorStore::class);
        $this->app->forgetInstance(CoordinatorResolver::class);
        $this->app->forgetInstance(CoordinatorStore::class);
    }

    private function forgetTransport(): void
    {
        $this->app->forgetInstance(RemoteWorkerConfiguration::class);
        $this->app->forgetInstance(RemoteWorkerProcessFactory::class);
        $this->app->forgetInstance(WorkerTransportResolver::class);
        $this->app->forgetInstance(WorkerProcessFactory::class);
    }
}

final class UnusedRedisFactory implements RedisFactory
{
    public function connection($name = null): never
    {
        throw new RuntimeException('Redis connection should remain lazy.');
    }
}
