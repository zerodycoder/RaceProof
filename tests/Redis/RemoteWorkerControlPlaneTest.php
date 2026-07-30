<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Redis;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Remote\RedisWorkerControlPlane;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Tests\Integration\TestCase;

final class RemoteWorkerControlPlaneTest extends TestCase
{
    private string $namespace;

    private ?RedisWorkerControlPlane $control = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RACEPROOF_REDIS_TEST') !== '1') {
            $this->markTestSkipped('Real Redis evidence is enabled only in the dedicated Redis job.');
        }

        $this->namespace = 'raceproof-remote-ci-'.bin2hex(random_bytes(6));
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
        $this->app['config']->set('raceproof.coordinator.redis.connection', 'default');
        $this->app['config']->set('raceproof.worker_transport.driver', 'remote');
        $this->app['config']->set('raceproof.worker_transport.remote.namespace', $this->namespace);
        $this->app['config']->set(
            'raceproof.worker_transport.remote.secret',
            'ci-control-secret-0123456789abcdef',
        );
        $this->app['config']->set('raceproof.worker_transport.remote.agents', ['agent-a']);
        $this->app['config']->set('raceproof.worker_transport.remote.state_ttl_seconds', 60);
        $this->forgetRemoteControl();
        $control = $this->app->make(WorkerControlPlane::class);
        self::assertInstanceOf(RedisWorkerControlPlane::class, $control);
        $this->control = $control;
    }

    protected function tearDown(): void
    {
        if (isset($this->namespace)) {
            $connection = $this->connection();
            $keys = $connection->command('keys', [$this->namespace.':*']);
            self::assertIsArray($keys);

            if ($keys !== []) {
                $connection->command('del', array_values($keys));
            }
        }

        parent::tearDown();
    }

    public function test_real_redis_authentication_replay_lifecycle_and_ttl_are_atomic(): void
    {
        $control = $this->requiredControl();
        $codec = $this->app->make(RemoteControlMessageCodec::class);
        self::assertInstanceOf(RemoteControlMessageCodec::class, $codec);
        $runId = bin2hex(random_bytes(16));
        $control->healthCheck();
        self::assertFalse($control->agentAvailable('agent-a'));
        $control->heartbeat('agent-a');
        self::assertTrue($control->agentAvailable('agent-a'));
        $start = $codec->issue('start', 'agent-a', $runId, 'p1');
        $control->dispatch($start['message'], $start['envelope']);
        $encoded = $control->next('agent-a', 'start');
        self::assertIsString($encoded);
        $message = $codec->decode($encoded, 'agent-a');
        self::assertTrue($control->claim($message));
        self::assertFalse($control->claim($message));
        self::assertTrue($control->markRunning($message));
        $control->finish($runId, 'p1', 0, 'ok', '', false);

        $state = $control->state($runId, 'p1');
        self::assertNotNull($state);
        self::assertSame('completed', $state->status);
        self::assertSame(0, $state->exitCode);
        self::assertSame('ok', $state->output);
        $ttl = $this->connection()->command('pttl', [
            "{$this->namespace}:worker:{$runId}:p1",
        ]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(60_000, $ttl);
    }

    private function requiredControl(): RedisWorkerControlPlane
    {
        if ($this->control === null) {
            self::fail('Remote worker control plane was not initialized.');
        }

        return $this->control;
    }

    private function connection(): Connection
    {
        return $this->app->make(RedisFactory::class)->connection('default');
    }

    private function forgetRemoteControl(): void
    {
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance(RedisFactory::class);
        $this->app->forgetInstance(RemoteWorkerConfiguration::class);
        $this->app->forgetInstance(RedisWorkerControlPlane::class);
        $this->app->forgetInstance(WorkerControlPlane::class);
        $this->app->forgetInstance(RemoteControlMessageCodec::class);
    }
}
