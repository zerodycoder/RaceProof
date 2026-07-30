<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Contracts\WorkerTransport;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\SymfonyWorkerProcessFactory;
use RaceProof\Laravel\Execution\WorkerTransportResolver;
use RaceProof\Laravel\Remote\RemoteWorkerProcessFactory;
use stdClass;

final class WorkerTransportResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_resolves_and_caches_the_local_transport(): void
    {
        $transport = new ResolverWorkerTransport('local');
        $resolver = $this->resolver('local', 'file', SymfonyWorkerProcessFactory::class, $transport);

        self::assertSame('local', $resolver->driver());
        self::assertSame($transport->process, $resolver->create(str_repeat('a', 32), 'p1'));
        $resolver->healthCheck();
        self::assertSame(1, $transport->healthChecks);
    }

    public function test_it_resolves_remote_only_with_the_redis_coordinator(): void
    {
        $transport = new ResolverWorkerTransport('remote');
        $resolver = $this->resolver('remote', 'redis', RemoteWorkerProcessFactory::class, $transport);

        self::assertSame('remote', $resolver->driver());
    }

    public function test_remote_transport_rejects_the_file_coordinator_before_container_resolution(): void
    {
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $coordinator = Mockery::mock(CoordinatorStore::class);
        $config->shouldReceive('get')->once()->with('raceproof.worker_transport.driver')->andReturn('remote');
        $coordinator->shouldReceive('driver')->once()->andReturn('file');

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('requires the Redis coordinator');

        (new WorkerTransportResolver($config, $container, $coordinator))->driver();
    }

    public function test_unknown_transport_is_rejected_without_echoing_configuration(): void
    {
        $secret = 'https://user:super-secret@example.test';
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $coordinator = Mockery::mock(CoordinatorStore::class);
        $config->shouldReceive('get')->once()->with('raceproof.worker_transport.driver')->andReturn($secret);
        $resolver = new WorkerTransportResolver($config, $container, $coordinator);

        try {
            $resolver->driver();
            self::fail('Expected unknown transport to fail.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof worker transport configuration is unsupported.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }

    public function test_it_rejects_an_invalid_transport_implementation(): void
    {
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $coordinator = Mockery::mock(CoordinatorStore::class);
        $config->shouldReceive('get')->once()->with('raceproof.worker_transport.driver')->andReturn('local');
        $container->shouldReceive('make')->once()->with(SymfonyWorkerProcessFactory::class)->andReturn(new stdClass);
        $resolver = new WorkerTransportResolver($config, $container, $coordinator);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('invalid implementation');

        $resolver->driver();
    }

    private function resolver(
        string $driver,
        string $coordinatorDriver,
        string $class,
        WorkerTransport $transport,
    ): WorkerTransportResolver {
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $coordinator = Mockery::mock(CoordinatorStore::class);
        $config->shouldReceive('get')->once()->with('raceproof.worker_transport.driver')->andReturn($driver);

        if ($driver === 'remote') {
            $coordinator->shouldReceive('driver')->once()->andReturn($coordinatorDriver);
        }

        $container->shouldReceive('make')->once()->with($class)->andReturn($transport);

        return new WorkerTransportResolver($config, $container, $coordinator);
    }
}

final class ResolverWorkerTransport implements WorkerTransport
{
    public int $healthChecks = 0;

    public WorkerProcess $process;

    public function __construct(private readonly string $name)
    {
        $this->process = Mockery::mock(WorkerProcess::class);
    }

    public function create(string $runId, string $participantId): WorkerProcess
    {
        return $this->process;
    }

    public function driver(): string
    {
        return $this->name;
    }

    public function healthCheck(): void
    {
        $this->healthChecks++;
    }
}
