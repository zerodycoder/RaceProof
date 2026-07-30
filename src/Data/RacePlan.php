<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;
use RaceProof\Laravel\Support\ParticipantId;

final readonly class RacePlan implements JsonSerializable
{
    /**
     * @param  list<string>  $checkpoints
     * @param  array<string, ParticipantSpec>  $participantSpecs
     */
    public function __construct(
        public string $runId,
        public int $participants,
        public ?RequestSpec $request,
        public ?AuthSpec $auth = null,
        public array $checkpoints = [],
        public int $spawnTimeoutMs = 10_000,
        public int $runTimeoutMs = 15_000,
        public int $pollIntervalMs = 5,
        public ?BootstrapSpec $bootstrap = null,
        public array $participantSpecs = [],
        public ?QueueSpec $queue = null,
    ) {
        if (! preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new InvalidRacePlan('Run ID must be a 32 character lowercase hex value.');
        }

        if ($participants < 2 || $participants > 100) {
            throw new InvalidRacePlan('Participants must be between 2 and 100.');
        }

        if ($spawnTimeoutMs < 1 || $runTimeoutMs < 1 || $pollIntervalMs < 1) {
            throw new InvalidRacePlan('Race timeouts and polling interval must be positive.');
        }

        if (($request === null) === ($queue === null)) {
            throw new InvalidRacePlan('A race plan must define exactly one request or queue workload.');
        }

        if ($queue !== null) {
            $queue->validateParticipants($participants);

            if ($auth !== null) {
                throw new InvalidRacePlan('Queue races do not support request authentication.');
            }
        }

        foreach ($checkpoints as $checkpoint) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $checkpoint)) {
                throw new InvalidRacePlan("Invalid checkpoint name [{$checkpoint}].");
            }
        }

        foreach ($participantSpecs as $participantId => $spec) {
            if (! is_string($participantId) || ! $spec instanceof ParticipantSpec) {
                throw new InvalidRacePlan('Participant overrides must map participant IDs to participant specs.');
            }

            if (ParticipantId::number($participantId) > $participants) {
                throw new InvalidRacePlan(
                    "Participant override [{$participantId}] is outside this {$participants}-participant race.",
                );
            }

            if (
                $queue !== null
                && (
                    $spec->payload !== null
                    || $spec->headers !== []
                    || $spec->cookies !== []
                    || $spec->auth !== null
                )
            ) {
                throw new InvalidRacePlan(
                    'Queue participant overrides may configure bootstrap only.',
                );
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $auth = $data['auth'] ?? null;
        $bootstrap = $data['bootstrap'] ?? null;
        $request = $data['request'] ?? null;
        $queue = $data['queue'] ?? null;
        $participantSpecs = [];

        foreach (Input::map($data, 'participant_specs') as $participantId => $spec) {
            $participantSpecs[$participantId] = ParticipantSpec::fromArray(
                Input::mapValue($spec, "participant_specs.{$participantId}"),
            );
        }

        return new self(
            runId: Input::string($data, 'run_id'),
            participants: Input::integer($data, 'participants'),
            request: $request === null ? null : RequestSpec::fromArray(Input::mapValue($request, 'request')),
            auth: $auth === null ? null : AuthSpec::fromArray(Input::mapValue($auth, 'auth')),
            checkpoints: Input::stringList($data, 'checkpoints'),
            spawnTimeoutMs: Input::integer($data, 'spawn_timeout_ms', 10_000),
            runTimeoutMs: Input::integer($data, 'run_timeout_ms', 15_000),
            pollIntervalMs: Input::integer($data, 'poll_interval_ms', 5),
            bootstrap: $bootstrap === null ? null : BootstrapSpec::fromArray(Input::mapValue($bootstrap, 'bootstrap')),
            participantSpecs: $participantSpecs,
            queue: $queue === null ? null : QueueSpec::fromArray(Input::mapValue($queue, 'queue')),
        );
    }

    public function requestFor(string $participantId): RequestSpec
    {
        if ($this->request === null) {
            throw new InvalidRacePlan('Queue race participants do not have HTTP request specifications.');
        }

        return $this->participantSpec($participantId)?->request($this->request) ?? $this->request;
    }

    public function authFor(string $participantId): ?AuthSpec
    {
        $spec = $this->participantSpec($participantId);

        return $spec === null || $spec->auth === null ? $this->auth : $spec->auth;
    }

    public function bootstrapFor(string $participantId): ?BootstrapSpec
    {
        $spec = $this->participantSpec($participantId);

        return $spec === null || $spec->bootstrap === null ? $this->bootstrap : $spec->bootstrap;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            'participants' => $this->participants,
            'request' => $this->request,
            'auth' => $this->auth,
            'checkpoints' => $this->checkpoints,
            'spawn_timeout_ms' => $this->spawnTimeoutMs,
            'run_timeout_ms' => $this->runTimeoutMs,
            'poll_interval_ms' => $this->pollIntervalMs,
            'bootstrap' => $this->bootstrap,
            'participant_specs' => (object) $this->participantSpecs,
            'queue' => $this->queue,
        ];
    }

    private function participantSpec(string $participantId): ?ParticipantSpec
    {
        if (ParticipantId::number($participantId) > $this->participants) {
            throw new InvalidRacePlan(
                "Participant [{$participantId}] is outside this {$this->participants}-participant race.",
            );
        }

        return $this->participantSpecs[$participantId] ?? null;
    }
}
