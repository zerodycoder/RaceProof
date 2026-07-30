<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Coordination;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory;
use RuntimeException;

/**
 * @internal Adapts Laravel's PhpRedis and Predis connections to one command shape.
 */
final class LaravelRedisClient implements RedisClient
{
    private ?Connection $connection = null;

    public function __construct(
        private readonly Factory $factory,
        private readonly string $connectionName,
    ) {}

    public function command(string $command, array $arguments = []): mixed
    {
        return $this->connection()->command(strtolower($command), $arguments);
    }

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        $evaluate = [$this->connection(), 'eval'];

        if (! is_callable($evaluate)) {
            throw new RuntimeException('The configured Redis connection cannot execute scripts.');
        }

        return $evaluate($script, count($keys), ...$keys, ...$arguments);
    }

    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $connection = $this->factory->connection($this->connectionName);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('Laravel resolved an invalid Redis connection.');
        }

        return $this->connection = $connection;
    }
}
