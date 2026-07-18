<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;

final readonly class RacePlan implements JsonSerializable
{
    /** @param list<string> $checkpoints */
    public function __construct(
        public string $runId,
        public int $participants,
        public RequestSpec $request,
        public ?AuthSpec $auth = null,
        public array $checkpoints = [],
        public int $spawnTimeoutMs = 10_000,
        public int $runTimeoutMs = 15_000,
        public int $pollIntervalMs = 5,
        public ?BootstrapSpec $bootstrap = null,
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

        foreach ($checkpoints as $checkpoint) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $checkpoint)) {
                throw new InvalidRacePlan("Invalid checkpoint name [{$checkpoint}].");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $auth = $data['auth'] ?? null;
        $bootstrap = $data['bootstrap'] ?? null;

        return new self(
            runId: Input::string($data, 'run_id'),
            participants: Input::integer($data, 'participants'),
            request: RequestSpec::fromArray(Input::map($data, 'request')),
            auth: $auth === null ? null : AuthSpec::fromArray(Input::mapValue($auth, 'auth')),
            checkpoints: Input::stringList($data, 'checkpoints'),
            spawnTimeoutMs: Input::integer($data, 'spawn_timeout_ms', 10_000),
            runTimeoutMs: Input::integer($data, 'run_timeout_ms', 15_000),
            pollIntervalMs: Input::integer($data, 'poll_interval_ms', 5),
            bootstrap: $bootstrap === null ? null : BootstrapSpec::fromArray(Input::mapValue($bootstrap, 'bootstrap')),
        );
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
        ];
    }
}
