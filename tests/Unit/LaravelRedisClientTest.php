<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\LaravelRedisClient;
use RuntimeException;
use stdClass;

final class LaravelRedisClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_normalizes_commands_and_scripts_and_caches_the_connection(): void
    {
        $factory = Mockery::mock(Factory::class);
        $connection = new RecordingRedisConnection;
        $factory->shouldReceive('connection')
            ->once()
            ->with('raceproof')
            ->andReturn($connection);
        $client = new LaravelRedisClient($factory, 'raceproof');

        self::assertSame('PONG', $client->command('PING'));
        self::assertSame(7, $client->evaluate('return 7', ['key-one'], ['argument']));
        self::assertSame([
            ['command' => 'ping', 'arguments' => []],
        ], $connection->commands);
        self::assertSame([
            [
                'script' => 'return 7',
                'number_of_keys' => 1,
                'arguments' => ['key-one', 'argument'],
            ],
        ], $connection->scripts);
    }

    public function test_it_rejects_an_invalid_connection_resolution(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')
            ->once()
            ->with('raceproof')
            ->andReturn(new stdClass);
        $client = new LaravelRedisClient($factory, 'raceproof');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid Redis connection');

        $client->command('ping');
    }
}

final class RecordingRedisConnection implements Connection
{
    /** @var list<array{command: string, arguments: array<int, mixed>}> */
    public array $commands = [];

    /** @var list<array{script: string, number_of_keys: int, arguments: array<int, mixed>}> */
    public array $scripts = [];

    public function subscribe($channels, \Closure $callback): void {}

    public function psubscribe($channels, \Closure $callback): void {}

    public function command($method, array $parameters = []): mixed
    {
        $this->commands[] = ['command' => $method, 'arguments' => $parameters];

        return 'PONG';
    }

    public function eval(string $script, int $numberOfKeys, mixed ...$arguments): int
    {
        $this->scripts[] = [
            'script' => $script,
            'number_of_keys' => $numberOfKeys,
            'arguments' => $arguments,
        ];

        return 7;
    }
}
