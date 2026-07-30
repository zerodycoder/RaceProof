<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Coordination;

use JsonException;
use JsonSerializable;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\CoordinationTimeout;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceTimeline;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\Input;
use Throwable;

final class RedisCoordinatorStore implements CoordinatorStore
{
    private const MIN_TTL_SECONDS = 60;

    private const MAX_TTL_SECONDS = 604_800;

    private const CREATE_RUN_SCRIPT = <<<'LUA'
-- raceproof:create-run
if redis.call('EXISTS', KEYS[1]) == 1 then
    return 0
end
redis.call('HSET', KEYS[1],
    'plan', ARGV[1],
    'timeline_seq', 1,
    'event:1', ARGV[2],
    'event-id:' .. ARGV[3], 1
)
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', tonumber(ARGV[5]))
redis.call('ZADD', KEYS[2], tonumber(ARGV[6]), ARGV[7])
return 1
LUA;

    private const TRANSITION_SCRIPT = <<<'LUA'
-- raceproof:transition
if redis.call('HEXISTS', KEYS[1], 'plan') == 0 then
    return -1
end
local changed = redis.call('HSETNX', KEYS[1], ARGV[1], ARGV[2])
if changed == 1 then
    local sequence = redis.call('HINCRBY', KEYS[1], 'timeline_seq', 1)
    redis.call('HSET', KEYS[1],
        'event:' .. sequence, ARGV[3],
        'event-id:' .. ARGV[4], 1
    )
end
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[5]))
redis.call('ZADD', KEYS[2], tonumber(ARGV[6]), ARGV[7])
return changed
LUA;

    private const APPEND_EVENT_SCRIPT = <<<'LUA'
-- raceproof:append-event
if redis.call('HEXISTS', KEYS[1], 'plan') == 0 then
    return -1
end
local changed = redis.call('HSETNX', KEYS[1], 'event-id:' .. ARGV[1], 1)
if changed == 1 then
    local sequence = redis.call('HINCRBY', KEYS[1], 'timeline_seq', 1)
    redis.call('HSET', KEYS[1], 'event:' .. sequence, ARGV[2])
end
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
redis.call('ZADD', KEYS[2], tonumber(ARGV[4]), ARGV[5])
return changed
LUA;

    private const RETAINED_RUNS_SCRIPT = <<<'LUA'
-- raceproof:retained-runs
local members = redis.call('ZRANGE', KEYS[1], 0, -1, 'WITHSCORES')
local retained = {}
for index = 1, #members, 2 do
    local runId = members[index]
    local expiresAt = tonumber(members[index + 1])
    local valid = string.len(runId) == 32 and string.match(runId, '^[a-f0-9]+$') ~= nil
    local stateKey = ARGV[2] .. runId .. '}'
    if not valid or expiresAt <= tonumber(ARGV[1]) or redis.call('EXISTS', stateKey) == 0 then
        redis.call('ZREM', KEYS[1], runId)
    else
        table.insert(retained, runId)
    end
end
return retained
LUA;

    private const CLEANUP_SCRIPT = <<<'LUA'
-- raceproof:cleanup
redis.call('DEL', KEYS[1])
redis.call('ZREM', KEYS[2], ARGV[1])
return 1
LUA;

    private const HEALTH_SCRIPT = <<<'LUA'
-- raceproof:health
local created = redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', tonumber(ARGV[2]))
if not created then
    return 0
end
local stored = redis.call('GET', KEYS[1])
redis.call('DEL', KEYS[1])
if stored == ARGV[1] then
    return 1
end
return 0
LUA;

    public function __construct(
        private readonly RedisClient $client,
        private readonly string $connectionName,
        private readonly string $namespace,
        private readonly int $ttlSeconds,
        private readonly int $pollIntervalMs = 5,
    ) {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $connectionName)) {
            throw new RaceProofException('RaceProof Redis connection name configuration is invalid.');
        }

        if (! preg_match('/^[A-Za-z][A-Za-z0-9:_-]{0,63}$/D', $namespace)) {
            throw new RaceProofException('RaceProof Redis namespace configuration is invalid.');
        }

        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new RaceProofException(
                'RaceProof Redis TTL must be between 60 and 604800 seconds.',
            );
        }

        if ($pollIntervalMs < 1 || $pollIntervalMs > 1_000) {
            throw new RaceProofException(
                'RaceProof Redis poll interval must be between 1 and 1000 milliseconds.',
            );
        }
    }

    public function driver(): string
    {
        return 'redis';
    }

    public function healthCheck(): void
    {
        $ping = $this->call(fn (): mixed => $this->client->command('ping'));

        if ($ping === false || $ping === null) {
            throw new RaceProofException('RaceProof Redis coordinator health check failed.');
        }

        $probe = $this->namespace.':health:'.bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $result = $this->call(fn (): mixed => $this->client->evaluate(
            self::HEALTH_SCRIPT,
            [$probe],
            [$token, 10],
        ));

        if ($this->integerResult($result) !== 1) {
            throw new RaceProofException('RaceProof Redis coordinator health check failed.');
        }
    }

    public function retainedRunIds(): array
    {
        $value = $this->call(fn (): mixed => $this->client->evaluate(
            self::RETAINED_RUNS_SCRIPT,
            [$this->indexKey()],
            [time(), $this->stateKeyPrefix()],
        ));
        $runIds = $this->stringList($value);
        $runIds = array_values(array_filter(
            $runIds,
            static fn (string $runId): bool => preg_match('/^[a-f0-9]{32}$/D', $runId) === 1,
        ));
        sort($runIds, SORT_STRING);

        return $runIds;
    }

    public function artifactReference(string $runId): string
    {
        $this->stateKey($runId);

        return "redis://{$this->connectionName}/{$this->namespace}/runs/{$runId}";
    }

    public function createRun(RacePlan $plan): void
    {
        $event = TimelineEvent::make($plan->runId, 'run.created', data: [
            'participants' => $plan->participants,
            'checkpoint_count' => count($plan->checkpoints),
            'participant_override_count' => count($plan->participantSpecs),
        ]);
        $result = $this->call(fn (): mixed => $this->client->evaluate(
            self::CREATE_RUN_SCRIPT,
            [$this->stateKey($plan->runId), $this->indexKey()],
            [
                $this->encode($plan),
                $this->encode($event),
                $event->eventId,
                $this->ttlSeconds,
                time(),
                $this->expiresAt(),
                $plan->runId,
            ],
        ));

        if ($this->integerResult($result) !== 1) {
            throw new RaceProofException("Race run [{$plan->runId}] already exists.");
        }
    }

    public function plan(string $runId): RacePlan
    {
        $contents = $this->hashValue($runId, 'plan');

        if ($contents === null) {
            throw new RaceProofException("Race plan [{$runId}] does not exist.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return RacePlan::fromArray(Input::mapValue($decoded, 'plan'));
        } catch (JsonException $exception) {
            throw new RaceProofException("Race plan [{$runId}] contains invalid JSON.", 0, $exception);
        }
    }

    public function markReady(string $runId, string $participantId): void
    {
        $participantId = $this->safeParticipant($participantId);
        $this->transition(
            $runId,
            'ready:'.$participantId,
            (string) Clock::nowNs(),
            TimelineEvent::make($runId, 'participant.ready', $participantId),
        );
    }

    public function readyCount(string $runId): int
    {
        return $this->fieldCount($runId, 'ready:');
    }

    public function releaseStart(string $runId): void
    {
        $releasedAt = Clock::nowNs();
        $this->transition(
            $runId,
            'start.release',
            (string) $releasedAt,
            TimelineEvent::make($runId, 'barrier.start_released', occurredAtNs: $releasedAt),
        );
    }

    public function waitForStart(string $runId, int $timeoutMs): void
    {
        $this->waitForField(
            $runId,
            'start.release',
            $timeoutMs,
            "Timed out waiting for the start barrier for run [{$runId}].",
        );
    }

    public function reachCheckpoint(string $runId, string $participantId, string $checkpoint): void
    {
        $participantId = $this->safeParticipant($participantId);
        $checkpoint = $this->safeCheckpoint($checkpoint);
        $reachedAt = Clock::nowNs();
        $this->transition(
            $runId,
            "checkpoint:{$checkpoint}:reached:{$participantId}",
            (string) $reachedAt,
            TimelineEvent::make(
                $runId,
                'checkpoint.reached',
                $participantId,
                $checkpoint,
                occurredAtNs: $reachedAt,
            ),
        );
    }

    public function checkpointCount(string $runId, string $checkpoint): int
    {
        $checkpoint = $this->safeCheckpoint($checkpoint);

        return $this->fieldCount($runId, "checkpoint:{$checkpoint}:reached:");
    }

    public function releaseCheckpoint(string $runId, string $checkpoint): void
    {
        $checkpoint = $this->safeCheckpoint($checkpoint);
        $releasedAt = Clock::nowNs();
        $this->transition(
            $runId,
            "checkpoint:{$checkpoint}:release",
            (string) $releasedAt,
            TimelineEvent::make(
                $runId,
                'checkpoint.released',
                checkpoint: $checkpoint,
                occurredAtNs: $releasedAt,
            ),
        );
    }

    public function waitForCheckpoint(string $runId, string $checkpoint, int $timeoutMs): void
    {
        $checkpoint = $this->safeCheckpoint($checkpoint);
        $this->waitForField(
            $runId,
            "checkpoint:{$checkpoint}:release",
            $timeoutMs,
            "Timed out waiting for checkpoint [{$checkpoint}] in run [{$runId}].",
        );
    }

    public function storeResult(ParticipantResult $result): void
    {
        $participantId = $this->safeParticipant($result->participantId);
        $encoded = $this->encode($result);
        $outcome = $result->workerError !== null
            ? 'worker_error'
            : ($result->exceptionClass !== null ? 'exception' : 'response');
        $changed = $this->transition(
            $result->runId,
            'result:'.$participantId,
            $encoded,
            TimelineEvent::make(
                $result->runId,
                'participant.finished',
                $participantId,
                data: [
                    'outcome' => $outcome,
                    'status' => $result->status,
                    'duration_ms' => $result->durationMs(),
                    'exception_class' => $result->exceptionClass,
                ],
                occurredAtNs: $result->finishedAtNs,
            ),
        );

        if (! $changed && $this->hashValue($result->runId, 'result:'.$participantId) !== $encoded) {
            throw new RaceProofException(
                "Race run [{$result->runId}] already stores a different result for [{$participantId}].",
            );
        }
    }

    public function results(string $runId): array
    {
        $values = $this->hash($runId);
        $results = [];

        foreach ($values as $field => $contents) {
            if (! str_starts_with($field, 'result:')) {
                continue;
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $results[] = ParticipantResult::fromArray(
                    Input::mapValue($decoded, 'participant result'),
                );
            } catch (JsonException) {
                // The orchestrator reports a missing result as a worker failure.
            }
        }

        usort(
            $results,
            static fn (ParticipantResult $left, ParticipantResult $right): int => $left->participantId <=> $right->participantId,
        );

        return $results;
    }

    public function recordEvent(TimelineEvent $event): void
    {
        $result = $this->call(fn (): mixed => $this->client->evaluate(
            self::APPEND_EVENT_SCRIPT,
            [$this->stateKey($event->runId), $this->indexKey()],
            [
                $event->eventId,
                $this->encode($event),
                $this->ttlSeconds,
                $this->expiresAt(),
                $event->runId,
            ],
        ));

        if ($this->integerResult($result) === -1) {
            throw new RaceProofException(
                "Cannot record timeline event for missing run [{$event->runId}].",
            );
        }
    }

    public function timeline(string $runId): RaceTimeline
    {
        $values = $this->hash($runId);

        if (! isset($values['plan'])) {
            return new RaceTimeline($runId, warnings: ['Timeline state is missing.']);
        }

        $sequenced = [];
        $warnings = [];

        foreach ($values as $field => $contents) {
            if (preg_match('/^event:([1-9][0-9]*)$/D', $field, $matches) !== 1) {
                continue;
            }

            $sequence = (int) $matches[1];

            try {
                $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
                $event = TimelineEvent::fromArray(Input::mapValue($decoded, 'timeline event'));

                if ($event->runId !== $runId) {
                    throw new RaceProofException('Timeline event belongs to another run.');
                }

                $sequenced[$sequence] = $event;
            } catch (Throwable) {
                $warnings[] = "Timeline event {$sequence} is malformed and was ignored.";
            }
        }

        ksort($sequenced, SORT_NUMERIC);

        return new RaceTimeline($runId, array_values($sequenced), $warnings);
    }

    public function cleanup(string $runId): void
    {
        $this->call(fn (): mixed => $this->client->evaluate(
            self::CLEANUP_SCRIPT,
            [$this->stateKey($runId), $this->indexKey()],
            [$runId],
        ));
    }

    private function transition(
        string $runId,
        string $field,
        string $value,
        TimelineEvent $event,
    ): bool {
        $result = $this->call(fn (): mixed => $this->client->evaluate(
            self::TRANSITION_SCRIPT,
            [$this->stateKey($runId), $this->indexKey()],
            [
                $field,
                $value,
                $this->encode($event),
                $event->eventId,
                $this->ttlSeconds,
                $this->expiresAt(),
                $runId,
            ],
        ));

        $result = $this->integerResult($result);

        if ($result === -1) {
            throw new RaceProofException("Race run [{$runId}] does not exist.");
        }

        return $result === 1;
    }

    private function hashValue(string $runId, string $field): ?string
    {
        $value = $this->call(fn (): mixed => $this->client->command(
            'hget',
            [$this->stateKey($runId), $field],
        ));

        if ($value === false || $value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private function hash(string $runId): array
    {
        $value = $this->call(fn (): mixed => $this->client->command(
            'hgetall',
            [$this->stateKey($runId)],
        ));

        if (! is_array($value)) {
            throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
        }

        if (array_is_list($value)) {
            if (count($value) % 2 !== 0) {
                throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
            }

            $pairs = [];

            for ($index = 0; $index < count($value); $index += 2) {
                if (! is_string($value[$index]) || ! is_string($value[$index + 1])) {
                    throw new RaceProofException(
                        'RaceProof Redis coordinator returned invalid state.',
                    );
                }

                $pairs[$value[$index]] = $value[$index + 1];
            }

            return $pairs;
        }

        $pairs = [];

        foreach ($value as $field => $contents) {
            if (! is_string($field) || ! is_string($contents)) {
                throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
            }

            $pairs[$field] = $contents;
        }

        return $pairs;
    }

    private function fieldCount(string $runId, string $prefix): int
    {
        $value = $this->call(fn (): mixed => $this->client->command(
            'hkeys',
            [$this->stateKey($runId)],
        ));

        return count(array_filter(
            $this->stringList($value),
            static fn (string $field): bool => str_starts_with($field, $prefix),
        ));
    }

    private function waitForField(
        string $runId,
        string $field,
        int $timeoutMs,
        string $message,
    ): void {
        $deadline = Clock::nowNs() + ($timeoutMs * 1_000_000);

        while (! $this->fieldExists($runId, $field)) {
            if (Clock::nowNs() >= $deadline) {
                throw new CoordinationTimeout($message);
            }

            usleep($this->pollIntervalMs * 1_000);
        }
    }

    private function fieldExists(string $runId, string $field): bool
    {
        $value = $this->call(fn (): mixed => $this->client->command(
            'hexists',
            [$this->stateKey($runId), $field],
        ));

        return $this->integerResult($value) === 1;
    }

    private function stateKey(string $runId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new RaceProofException('Invalid run ID.');
        }

        return $this->stateKeyPrefix().$runId.'}';
    }

    private function stateKeyPrefix(): string
    {
        return $this->namespace.':run:{';
    }

    private function indexKey(): string
    {
        return $this->namespace.':runs';
    }

    private function safeParticipant(string $participantId): string
    {
        if (preg_match('/^p[1-9][0-9]{0,2}$/D', $participantId) !== 1) {
            throw new RaceProofException("Invalid participant ID [{$participantId}].");
        }

        return $participantId;
    }

    private function safeCheckpoint(string $checkpoint): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $checkpoint) !== 1) {
            throw new RaceProofException("Invalid checkpoint name [{$checkpoint}].");
        }

        return $checkpoint;
    }

    private function expiresAt(): int
    {
        return time() + $this->ttlSeconds;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
            }
        }

        return $value;
    }

    private function integerResult(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new RaceProofException('RaceProof Redis coordinator returned invalid state.');
    }

    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            throw new RaceProofException(
                'RaceProof Redis coordinator is unavailable or misconfigured.',
            );
        }
    }

    private function encode(JsonSerializable $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RaceProofException('Unable to encode RaceProof data.', 0, $exception);
        }
    }
}
