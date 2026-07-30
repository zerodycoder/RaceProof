<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Queue;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\DatabaseSafety;
use Throwable;

/**
 * @internal Queue races support only bounded, clearable test backends.
 */
final readonly class QueueConnectionGuard
{
    public function __construct(
        private Config $config,
        private QueueFactory $queues,
        private DatabaseSafety $databaseSafety,
    ) {}

    public function name(string $connection): string
    {
        $name = $connection === 'default'
            ? $this->config->get('queue.default')
            : $connection;

        if (
            ! is_string($name)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $name) !== 1
        ) {
            throw new InvalidRacePlan('The queue connection name is missing or invalid.');
        }

        return $name;
    }

    public function resolve(string $connection): Queue&ClearableQueue
    {
        $connection = $this->name($connection);
        $configuration = $this->config->get("queue.connections.{$connection}");
        $driver = is_array($configuration) ? ($configuration['driver'] ?? null) : null;

        if (! is_string($driver) || ! in_array($driver, ['database', 'redis'], true)) {
            throw new InvalidRacePlan(
                'Queue races require an explicitly configured database or redis queue connection.',
            );
        }

        if ($driver === 'database') {
            $databaseConnection = $configuration['connection'] ?? null;

            if (
                $databaseConnection !== null
                && (
                    ! is_string($databaseConnection)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $databaseConnection) !== 1
                )
            ) {
                throw new InvalidRacePlan('The database queue connection is missing or invalid.');
            }

            $this->databaseSafety->validateConnection($databaseConnection);
        }

        try {
            $queue = $this->queues->connection($connection);
        } catch (Throwable) {
            throw new InvalidRacePlan(
                'The selected queue connection is unavailable or misconfigured.',
            );
        }

        if (! $queue instanceof ClearableQueue) {
            throw new InvalidRacePlan('Queue races require a clearable queue connection.');
        }

        return $queue;
    }
}
