<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;
use RaceProof\Laravel\Support\ParticipantId;

/**
 * @internal Queue execution details are serialized only in the run-scoped plan.
 */
final readonly class QueueSpec implements JsonSerializable
{
    /**
     * @param  array<string, string>  $jobClasses
     */
    public function __construct(
        public string $connection,
        public int $maxAttempts = 1,
        public int $backoffSeconds = 0,
        public array $jobClasses = [],
    ) {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $connection)) {
            throw new InvalidRacePlan('The queue connection name is invalid.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 5) {
            throw new InvalidRacePlan('Queue attempts must be between 1 and 5.');
        }

        if ($backoffSeconds < 0 || $backoffSeconds > 60) {
            throw new InvalidRacePlan('Queue backoff must be between 0 and 60 seconds.');
        }

        foreach ($jobClasses as $participantId => $jobClass) {
            if (! is_string($participantId) || ! is_string($jobClass)) {
                throw new InvalidRacePlan('Queue jobs must map participant IDs to job classes.');
            }

            ParticipantId::number($participantId);

            if (
                $jobClass === ''
                || strlen($jobClass) > 255
                || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $jobClass) !== 1
            ) {
                throw new InvalidRacePlan('The queue job class is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: Input::string($data, 'connection'),
            maxAttempts: Input::integer($data, 'max_attempts', 1),
            backoffSeconds: Input::integer($data, 'backoff_seconds', 0),
            jobClasses: Input::stringMap($data, 'job_classes'),
        );
    }

    /** @param array<string, string> $jobClasses */
    public function withJobClasses(array $jobClasses): self
    {
        return new self(
            connection: $this->connection,
            maxAttempts: $this->maxAttempts,
            backoffSeconds: $this->backoffSeconds,
            jobClasses: $jobClasses,
        );
    }

    public function withConnection(string $connection): self
    {
        return new self(
            connection: $connection,
            maxAttempts: $this->maxAttempts,
            backoffSeconds: $this->backoffSeconds,
            jobClasses: $this->jobClasses,
        );
    }

    public function validateParticipants(int $participants): void
    {
        if (count($this->jobClasses) !== $participants) {
            throw new InvalidRacePlan('Queue races require exactly one job class per participant.');
        }

        for ($number = 1; $number <= $participants; $number++) {
            if (! isset($this->jobClasses['p'.$number])) {
                throw new InvalidRacePlan('Queue races require a job class for every generated participant.');
            }
        }
    }

    public function jobClassFor(string $participantId): string
    {
        ParticipantId::number($participantId);
        $jobClass = $this->jobClasses[$participantId] ?? null;

        if ($jobClass === null) {
            throw new InvalidRacePlan("Queue participant [{$participantId}] has no job class.");
        }

        return $jobClass;
    }

    public function queueFor(string $runId, string $participantId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new InvalidRacePlan('Run ID must be a 32 character lowercase hex value.');
        }

        ParticipantId::number($participantId);

        return "raceproof:{$runId}:{$participantId}";
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'connection' => $this->connection,
            'max_attempts' => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
            'job_classes' => (object) $this->jobClasses,
        ];
    }
}
