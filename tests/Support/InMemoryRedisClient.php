<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Support;

use RaceProof\Laravel\Coordination\RedisClient;
use RuntimeException;
use Throwable;

final class InMemoryRedisClient implements RedisClient
{
    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, array<string, int>> */
    public array $indexes = [];

    /** @var list<array{command: string, arguments: list<int|string>}> */
    public array $commands = [];

    /** @var list<string> */
    public array $scripts = [];

    public ?Throwable $failure = null;

    public mixed $commandOverride = null;

    public function command(string $command, array $arguments = []): mixed
    {
        $this->failIfConfigured();
        $command = strtolower($command);
        $this->commands[] = compact('command', 'arguments');

        if ($this->commandOverride !== null) {
            return $this->commandOverride;
        }

        return match ($command) {
            'ping' => 'PONG',
            'hget' => $this->hashes[(string) $arguments[0]][(string) $arguments[1]] ?? null,
            'hgetall' => $this->hashes[(string) $arguments[0]] ?? [],
            'hkeys' => array_keys($this->hashes[(string) $arguments[0]] ?? []),
            'hexists' => isset($this->hashes[(string) $arguments[0]][(string) $arguments[1]]) ? 1 : 0,
            default => throw new RuntimeException("Unsupported in-memory Redis command [{$command}]."),
        };
    }

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        $this->failIfConfigured();
        $operation = $this->operation($script);
        $this->scripts[] = $operation;

        return match ($operation) {
            'create-run' => $this->createRun($keys, $arguments),
            'transition' => $this->transition($keys, $arguments),
            'append-event' => $this->appendEvent($keys, $arguments),
            'retained-runs' => $this->retainedRuns($keys, $arguments),
            'cleanup' => $this->cleanup($keys, $arguments),
            'health' => 1,
            default => throw new RuntimeException("Unsupported in-memory Redis script [{$operation}]."),
        };
    }

    public function injectIndex(string $indexKey, string $runId, int $expiresAt): void
    {
        $this->indexes[$indexKey][$runId] = $expiresAt;
    }

    private function createRun(array $keys, array $arguments): int
    {
        [$stateKey, $indexKey] = $keys;

        if (isset($this->hashes[$stateKey])) {
            return 0;
        }

        $this->hashes[$stateKey] = [
            'plan' => (string) $arguments[0],
            'timeline_seq' => '1',
            'event:1' => (string) $arguments[1],
            'event-id:'.(string) $arguments[2] => '1',
        ];
        $this->pruneExpired($indexKey, (int) $arguments[4]);
        $this->indexes[$indexKey][(string) $arguments[6]] = (int) $arguments[5];

        return 1;
    }

    private function transition(array $keys, array $arguments): int
    {
        [$stateKey, $indexKey] = $keys;

        if (! isset($this->hashes[$stateKey]['plan'])) {
            return -1;
        }

        $field = (string) $arguments[0];
        $changed = ! isset($this->hashes[$stateKey][$field]);

        if ($changed) {
            $this->hashes[$stateKey][$field] = (string) $arguments[1];
            $sequence = ((int) $this->hashes[$stateKey]['timeline_seq']) + 1;
            $this->hashes[$stateKey]['timeline_seq'] = (string) $sequence;
            $this->hashes[$stateKey]['event:'.$sequence] = (string) $arguments[2];
            $this->hashes[$stateKey]['event-id:'.(string) $arguments[3]] = '1';
        }

        $this->indexes[$indexKey][(string) $arguments[6]] = (int) $arguments[5];

        return $changed ? 1 : 0;
    }

    private function appendEvent(array $keys, array $arguments): int
    {
        [$stateKey, $indexKey] = $keys;

        if (! isset($this->hashes[$stateKey]['plan'])) {
            return -1;
        }

        $eventField = 'event-id:'.(string) $arguments[0];
        $changed = ! isset($this->hashes[$stateKey][$eventField]);

        if ($changed) {
            $this->hashes[$stateKey][$eventField] = '1';
            $sequence = ((int) $this->hashes[$stateKey]['timeline_seq']) + 1;
            $this->hashes[$stateKey]['timeline_seq'] = (string) $sequence;
            $this->hashes[$stateKey]['event:'.$sequence] = (string) $arguments[1];
        }

        $this->indexes[$indexKey][(string) $arguments[4]] = (int) $arguments[3];

        return $changed ? 1 : 0;
    }

    /** @return list<string> */
    private function retainedRuns(array $keys, array $arguments): array
    {
        $indexKey = $keys[0];
        $now = (int) $arguments[0];
        $statePrefix = (string) $arguments[1];
        $retained = [];

        foreach ($this->indexes[$indexKey] ?? [] as $runId => $expiresAt) {
            $valid = preg_match('/^[a-f0-9]{32}$/D', $runId) === 1;
            $stateKey = $statePrefix.$runId.'}';

            if (! $valid || $expiresAt <= $now || ! isset($this->hashes[$stateKey])) {
                unset($this->indexes[$indexKey][$runId]);

                continue;
            }

            $retained[] = $runId;
        }

        return $retained;
    }

    private function cleanup(array $keys, array $arguments): int
    {
        unset($this->hashes[$keys[0]], $this->indexes[$keys[1]][(string) $arguments[0]]);

        return 1;
    }

    private function pruneExpired(string $indexKey, int $now): void
    {
        foreach ($this->indexes[$indexKey] ?? [] as $runId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->indexes[$indexKey][$runId]);
            }
        }
    }

    private function operation(string $script): string
    {
        if (preg_match('/-- raceproof:([a-z-]+)/', $script, $matches) !== 1) {
            throw new RuntimeException('Redis script is missing an operation marker.');
        }

        return $matches[1];
    }

    private function failIfConfigured(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
