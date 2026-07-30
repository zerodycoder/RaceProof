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

    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var list<array{command: string, arguments: list<int|string>}> */
    public array $commands = [];

    /** @var list<string> */
    public array $scripts = [];

    /**
     * @var list<array{
     *     operation: string,
     *     keys: list<string>,
     *     arguments: list<int|string>
     * }>
     */
    public array $evaluations = [];

    public ?Throwable $failure = null;

    public mixed $commandOverride = null;

    /** @var array<string, mixed> */
    public array $commandOverrides = [];

    public mixed $evaluationOverride = null;

    public function command(string $command, array $arguments = []): mixed
    {
        $this->failIfConfigured();
        $command = strtolower($command);
        $this->commands[] = compact('command', 'arguments');

        if (array_key_exists($command, $this->commandOverrides)) {
            return $this->commandOverrides[$command];
        }

        if ($this->commandOverride !== null) {
            return $this->commandOverride;
        }

        return match ($command) {
            'ping' => 'PONG',
            'hget' => $this->hashes[(string) $arguments[0]][(string) $arguments[1]] ?? null,
            'hgetall' => $this->hashes[(string) $arguments[0]] ?? [],
            'hkeys' => array_keys($this->hashes[(string) $arguments[0]] ?? []),
            'hexists' => isset($this->hashes[(string) $arguments[0]][(string) $arguments[1]]) ? 1 : 0,
            'set' => $this->set($arguments),
            'get' => $this->strings[(string) $arguments[0]] ?? null,
            'rpop' => $this->rpop((string) $arguments[0]),
            default => throw new RuntimeException("Unsupported in-memory Redis command [{$command}]."),
        };
    }

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        $this->failIfConfigured();
        $operation = $this->operation($script);
        $this->scripts[] = $operation;
        $this->evaluations[] = compact('operation', 'keys', 'arguments');

        if ($this->evaluationOverride !== null) {
            return $this->evaluationOverride;
        }

        return match ($operation) {
            'create-run' => $this->createRun($keys, $arguments),
            'transition' => $this->transition($keys, $arguments),
            'append-event' => $this->appendEvent($keys, $arguments),
            'retained-runs' => $this->retainedRuns($keys, $arguments),
            'cleanup' => $this->cleanup($keys, $arguments),
            'health' => 1,
            'remote-dispatch-start' => $this->remoteDispatchStart($keys, $arguments),
            'remote-dispatch-stop' => $this->remoteDispatchStop($keys, $arguments),
            'remote-claim' => $this->remoteClaim($keys, $arguments),
            'remote-mark-running' => $this->remoteMarkRunning($keys),
            'remote-finish' => $this->remoteFinish($keys, $arguments),
            'remote-health' => 1,
            'remote-heartbeat' => $this->remoteHeartbeat($keys),
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

    /** @param list<int|string> $arguments */
    private function set(array $arguments): string
    {
        $this->strings[(string) $arguments[0]] = (string) $arguments[1];

        return 'OK';
    }

    private function rpop(string $key): ?string
    {
        if (($this->lists[$key] ?? []) === []) {
            return null;
        }

        return array_pop($this->lists[$key]);
    }

    /** @param list<int|string> $arguments */
    private function remoteDispatchStart(array $keys, array $arguments): int
    {
        [$stateKey, $inboxKey] = $keys;

        if (count($this->lists[$inboxKey] ?? []) >= (int) $arguments[7]) {
            return -4;
        }

        if (isset($this->hashes[$stateKey])) {
            return 0;
        }

        $this->hashes[$stateKey] = [
            'status' => 'pending',
            'agent_id' => (string) $arguments[2],
            'run_id' => (string) $arguments[3],
            'participant_id' => (string) $arguments[4],
            'expires_at_ms' => (string) $arguments[5],
            'output' => '',
            'error_output' => '',
        ];
        $this->lists[$inboxKey] ??= [];
        array_unshift($this->lists[$inboxKey], (string) $arguments[0]);

        return 1;
    }

    /** @param list<int|string> $arguments */
    private function remoteDispatchStop(array $keys, array $arguments): int
    {
        [$stateKey, $inboxKey] = $keys;

        if (! isset($this->hashes[$stateKey])) {
            return -1;
        }

        if ($this->hashes[$stateKey]['agent_id'] !== (string) $arguments[2]) {
            return -2;
        }

        if (in_array($this->hashes[$stateKey]['status'], ['completed', 'failed', 'stopped', 'cancelled'], true)) {
            return 0;
        }

        if (count($this->lists[$inboxKey] ?? []) >= (int) $arguments[7]) {
            return -4;
        }

        $this->lists[$inboxKey] ??= [];
        array_unshift($this->lists[$inboxKey], (string) $arguments[0]);

        return 1;
    }

    /** @param list<int|string> $arguments */
    private function remoteClaim(array $keys, array $arguments): int
    {
        [$stateKey, $seenKey] = $keys;

        if (! isset($this->hashes[$stateKey])) {
            return -1;
        }

        if (
            $this->hashes[$stateKey]['agent_id'] !== (string) $arguments[2]
            || $this->hashes[$stateKey]['run_id'] !== (string) $arguments[3]
            || $this->hashes[$stateKey]['participant_id'] !== (string) $arguments[4]
        ) {
            return -2;
        }

        if (isset($this->strings[$seenKey])) {
            return 0;
        }

        $this->strings[$seenKey] = (string) $arguments[5];
        $status = $this->hashes[$stateKey]['status'];

        if ($arguments[0] === 'start') {
            if ($status !== 'pending') {
                return 0;
            }

            $this->hashes[$stateKey]['status'] = 'claimed';

            return 1;
        }

        if ($arguments[0] === 'stop') {
            if ($status === 'pending') {
                $this->hashes[$stateKey]['status'] = 'cancelled';
                $this->hashes[$stateKey]['exit_code'] = '143';
                $this->hashes[$stateKey]['error_output'] = 'Remote worker was cancelled before launch.';

                return 1;
            }

            if (in_array($status, ['claimed', 'running'], true)) {
                $this->hashes[$stateKey]['status'] = 'stop_requested';

                return 1;
            }

            return 0;
        }

        return -3;
    }

    private function remoteMarkRunning(array $keys): int
    {
        $stateKey = $keys[0];

        if (! isset($this->hashes[$stateKey])) {
            return -1;
        }

        if ($this->hashes[$stateKey]['status'] !== 'claimed') {
            return 0;
        }

        $this->hashes[$stateKey]['status'] = 'running';

        return 1;
    }

    private function remoteHeartbeat(array $keys): int
    {
        $this->strings[$keys[0]] = '1';

        return 1;
    }

    /** @param list<int|string> $arguments */
    private function remoteFinish(array $keys, array $arguments): int
    {
        $stateKey = $keys[0];

        if (! isset($this->hashes[$stateKey])) {
            return -1;
        }

        $this->hashes[$stateKey]['status'] = (string) $arguments[0];
        $this->hashes[$stateKey]['exit_code'] = (string) $arguments[1];
        $this->hashes[$stateKey]['output'] = (string) $arguments[2];
        $this->hashes[$stateKey]['error_output'] = (string) $arguments[3];

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
