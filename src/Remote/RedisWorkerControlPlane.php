<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Coordination\RedisClient;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Exceptions\RemoteControlUnavailable;
use Throwable;

/**
 * @internal
 */
final readonly class RedisWorkerControlPlane implements WorkerControlPlane
{
    private const DISPATCH_START_SCRIPT = <<<'LUA'
        -- raceproof:remote-dispatch-start
        if redis.call('LLEN', KEYS[2]) >= tonumber(ARGV[8]) then
            return -4
        end
        if redis.call('EXISTS', KEYS[1]) == 1 then
            return 0
        end
        redis.call('HSET', KEYS[1],
            'status', 'pending',
            'agent_id', ARGV[3],
            'run_id', ARGV[4],
            'participant_id', ARGV[5],
            'expires_at_ms', ARGV[6],
            'output', '',
            'error_output', ''
        )
        redis.call('PEXPIRE', KEYS[1], ARGV[7])
        redis.call('LPUSH', KEYS[2], ARGV[1])
        redis.call('PEXPIRE', KEYS[2], ARGV[7])
        return 1
        LUA;

    private const DISPATCH_STOP_SCRIPT = <<<'LUA'
        -- raceproof:remote-dispatch-stop
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return -1
        end
        if redis.call('HGET', KEYS[1], 'agent_id') ~= ARGV[3] then
            return -2
        end
        local status = redis.call('HGET', KEYS[1], 'status')
        if status == 'completed' or status == 'failed' or status == 'stopped' or status == 'cancelled' then
            return 0
        end
        if redis.call('LLEN', KEYS[2]) >= tonumber(ARGV[8]) then
            return -4
        end
        redis.call('LPUSH', KEYS[2], ARGV[1])
        redis.call('PEXPIRE', KEYS[1], ARGV[7])
        redis.call('PEXPIRE', KEYS[2], ARGV[7])
        return 1
        LUA;

    private const CLAIM_SCRIPT = <<<'LUA'
        -- raceproof:remote-claim
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return -1
        end
        if redis.call('HGET', KEYS[1], 'agent_id') ~= ARGV[3]
            or redis.call('HGET', KEYS[1], 'run_id') ~= ARGV[4]
            or redis.call('HGET', KEYS[1], 'participant_id') ~= ARGV[5] then
            return -2
        end
        if redis.call('EXISTS', KEYS[2]) == 1 then
            return 0
        end
        redis.call('SET', KEYS[2], ARGV[6])
        redis.call('PEXPIRE', KEYS[2], ARGV[7])
        local status = redis.call('HGET', KEYS[1], 'status')
        if ARGV[1] == 'start' then
            if status ~= 'pending' then
                return 0
            end
            redis.call('HSET', KEYS[1], 'status', 'claimed')
            redis.call('PEXPIRE', KEYS[1], ARGV[7])
            return 1
        end
        if ARGV[1] == 'stop' then
            if status == 'pending' then
                redis.call('HSET', KEYS[1],
                    'status', 'cancelled',
                    'exit_code', '143',
                    'error_output', 'Remote worker was cancelled before launch.'
                )
                redis.call('PEXPIRE', KEYS[1], ARGV[7])
                return 1
            end
            if status == 'claimed' or status == 'running' then
                redis.call('HSET', KEYS[1], 'status', 'stop_requested')
                redis.call('PEXPIRE', KEYS[1], ARGV[7])
                return 1
            end
            return 0
        end
        return -3
        LUA;

    private const MARK_RUNNING_SCRIPT = <<<'LUA'
        -- raceproof:remote-mark-running
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return -1
        end
        local status = redis.call('HGET', KEYS[1], 'status')
        if status ~= 'claimed' then
            return 0
        end
        redis.call('HSET', KEYS[1], 'status', 'running')
        redis.call('PEXPIRE', KEYS[1], ARGV[1])
        return 1
        LUA;

    private const FINISH_SCRIPT = <<<'LUA'
        -- raceproof:remote-finish
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return -1
        end
        local status = ARGV[1]
        redis.call('HSET', KEYS[1],
            'status', status,
            'exit_code', ARGV[2],
            'output', ARGV[3],
            'error_output', ARGV[4]
        )
        redis.call('PEXPIRE', KEYS[1], ARGV[5])
        return 1
        LUA;

    private const HEALTH_SCRIPT = <<<'LUA'
        -- raceproof:remote-health
        redis.call('SET', KEYS[1], '1', 'PX', ARGV[1])
        redis.call('DEL', KEYS[1])
        return 1
        LUA;

    private const HEARTBEAT_SCRIPT = <<<'LUA'
        -- raceproof:remote-heartbeat
        redis.call('SET', KEYS[1], '1', 'PX', ARGV[1])
        return 1
        LUA;

    public function __construct(
        private RedisClient $client,
        private RemoteWorkerConfiguration $config,
    ) {}

    public function healthCheck(): void
    {
        $ping = $this->call(fn (): mixed => $this->client->command('ping'));

        if ($ping === false || $ping === null) {
            throw new RaceProofException('RaceProof remote worker control plane health check failed.');
        }

        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            self::HEALTH_SCRIPT,
            [$this->config->namespace.':health:'.bin2hex(random_bytes(8))],
            [$this->config->retentionMilliseconds()],
        )));

        if ($result !== 1) {
            throw new RaceProofException('RaceProof remote worker control plane health check failed.');
        }
    }

    public function heartbeat(string $agentId): void
    {
        $this->config->assertAgent($agentId);
        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            self::HEARTBEAT_SCRIPT,
            [$this->heartbeatKey($agentId)],
            [$this->config->heartbeatTtlMs],
        )));

        if ($result !== 1) {
            throw new RaceProofException('RaceProof remote worker control plane rejected an agent heartbeat.');
        }
    }

    public function agentAvailable(string $agentId): bool
    {
        $this->config->assertAgent($agentId);
        $result = $this->call(fn (): mixed => $this->client->command('get', [
            $this->heartbeatKey($agentId),
        ]));

        if ($result === null || $result === false) {
            return false;
        }

        if ($result !== '1') {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid agent state.');
        }

        return true;
    }

    public function dispatch(RemoteControlMessage $message, string $envelope): void
    {
        $script = $message->action === RemoteControlMessage::ACTION_START
            ? self::DISPATCH_START_SCRIPT
            : self::DISPATCH_STOP_SCRIPT;
        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            $script,
            [
                $this->stateKey($message->runId, $message->participantId),
                $this->inboxKey($message->agentId, $message->action),
            ],
            [
                $envelope,
                $message->messageId,
                $message->agentId,
                $message->runId,
                $message->participantId,
                $message->expiresAtMs,
                $this->config->retentionMilliseconds(),
                $this->config->maxPendingControls,
            ],
        )));

        if ($message->action === RemoteControlMessage::ACTION_START && $result === 0) {
            throw new RaceProofException('RaceProof remote worker control state already exists.');
        }

        if ($result < 0) {
            throw new RaceProofException('RaceProof remote worker control state is unavailable.');
        }
    }

    public function next(string $agentId, string $action): ?string
    {
        $this->config->assertAgent($agentId);

        if (! in_array($action, [RemoteControlMessage::ACTION_START, RemoteControlMessage::ACTION_STOP], true)) {
            throw new RaceProofException('RaceProof remote worker control action is invalid.');
        }

        $result = $this->call(fn (): mixed => $this->client->command('rpop', [
            $this->inboxKey($agentId, $action),
        ]));

        if ($result === null || $result === false) {
            return null;
        }

        if (! is_string($result)) {
            throw new RaceProofException('RaceProof remote worker control plane returned an invalid message.');
        }

        return $result;
    }

    public function claim(RemoteControlMessage $message): bool
    {
        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            self::CLAIM_SCRIPT,
            [
                $this->stateKey($message->runId, $message->participantId),
                $this->seenKey($message->agentId, $message->messageId),
            ],
            [
                $message->action,
                $message->messageId,
                $message->agentId,
                $message->runId,
                $message->participantId,
                $message->issuedAtMs,
                $this->config->retentionMilliseconds(),
            ],
        )));

        if ($result < 0) {
            throw new RaceProofException('RaceProof remote worker control message does not match its state.');
        }

        return $result === 1;
    }

    public function markRunning(RemoteControlMessage $message): bool
    {
        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            self::MARK_RUNNING_SCRIPT,
            [$this->stateKey($message->runId, $message->participantId)],
            [$this->config->retentionMilliseconds()],
        )));

        if ($result < 0) {
            throw new RaceProofException('RaceProof remote worker control state is unavailable.');
        }

        return $result === 1;
    }

    public function finish(
        string $runId,
        string $participantId,
        int $exitCode,
        string $output,
        string $errorOutput,
        bool $stopped,
    ): void {
        if (strlen($output) > $this->config->outputBytes || strlen($errorOutput) > $this->config->outputBytes) {
            throw new RaceProofException('RaceProof remote worker output exceeds its byte limit.');
        }

        $status = $stopped ? 'stopped' : ($exitCode === 0 ? 'completed' : 'failed');
        $result = $this->integerResult($this->call(fn (): mixed => $this->client->evaluate(
            self::FINISH_SCRIPT,
            [$this->stateKey($runId, $participantId)],
            [
                $status,
                $exitCode,
                $output,
                $errorOutput,
                $this->config->retentionMilliseconds(),
            ],
        )));

        if ($result !== 1) {
            throw new RaceProofException('RaceProof remote worker control state is unavailable.');
        }
    }

    public function state(string $runId, string $participantId): ?RemoteWorkerState
    {
        $value = $this->call(fn (): mixed => $this->client->command('hgetall', [
            $this->stateKey($runId, $participantId),
        ]));

        if (! is_array($value)) {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
        }

        $state = $this->stringMap($value);

        if ($state === []) {
            return null;
        }

        $worker = RemoteWorkerState::fromArray($state, $this->config->outputBytes);

        if ($worker->runId !== $runId || $worker->participantId !== $participantId) {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
        }

        return $worker;
    }

    private function stateKey(string $runId, string $participantId): string
    {
        return "{$this->config->namespace}:worker:{$runId}:{$participantId}";
    }

    private function inboxKey(string $agentId, string $action): string
    {
        return "{$this->config->namespace}:agent:{$agentId}:{$action}";
    }

    private function heartbeatKey(string $agentId): string
    {
        return "{$this->config->namespace}:agent:{$agentId}:heartbeat";
    }

    private function seenKey(string $agentId, string $messageId): string
    {
        return "{$this->config->namespace}:agent:{$agentId}:seen:{$messageId}";
    }

    private function call(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (RaceProofException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RemoteControlUnavailable(
                'RaceProof remote worker control plane is unavailable or misconfigured.',
            );
        }
    }

    private function integerResult(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, string>
     */
    private function stringMap(array $value): array
    {
        if (array_is_list($value)) {
            if (count($value) % 2 !== 0) {
                throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
            }

            $pairs = [];

            for ($index = 0; $index < count($value); $index += 2) {
                if (! is_string($value[$index]) || ! is_string($value[$index + 1])) {
                    throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
                }

                $pairs[$value[$index]] = $value[$index + 1];
            }

            return $pairs;
        }

        $pairs = [];

        foreach ($value as $field => $contents) {
            if (! is_string($field) || ! is_string($contents)) {
                throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
            }

            $pairs[$field] = $contents;
        }

        return $pairs;
    }
}
