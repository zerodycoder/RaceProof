<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\Clock;

final readonly class TimelineEvent implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, bool|float|int|string|null> $data */
    public function __construct(
        public int $schemaVersion,
        public string $eventId,
        public string $runId,
        public string $type,
        public int $occurredAtNs,
        public ?string $participantId = null,
        public ?string $checkpoint = null,
        public array $data = [],
    ) {
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new RaceProofException("Unsupported timeline schema version [{$schemaVersion}].");
        }

        if (! preg_match('/^[a-f0-9]{32}$/', $eventId)) {
            throw new RaceProofException('Timeline event ID must be a 32 character lowercase hex value.');
        }

        if (! preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new RaceProofException('Timeline run ID must be a 32 character lowercase hex value.');
        }

        if (! preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $type)) {
            throw new RaceProofException("Invalid timeline event type [{$type}].");
        }

        if ($occurredAtNs < 0) {
            throw new RaceProofException('Timeline event time cannot be negative.');
        }

        if ($participantId !== null && ! preg_match('/^p[1-9][0-9]{0,2}$/', $participantId)) {
            throw new RaceProofException("Invalid timeline participant ID [{$participantId}].");
        }

        if ($checkpoint !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $checkpoint)) {
            throw new RaceProofException("Invalid timeline checkpoint [{$checkpoint}].");
        }

        foreach ($data as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new RaceProofException('Timeline event data must contain scalar JSON values with string keys.');
            }
        }
    }

    /** @param array<string, bool|float|int|string|null> $data */
    public static function make(
        string $runId,
        string $type,
        ?string $participantId = null,
        ?string $checkpoint = null,
        array $data = [],
        ?int $occurredAtNs = null,
    ): self {
        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            eventId: bin2hex(random_bytes(16)),
            runId: $runId,
            type: $type,
            occurredAtNs: $occurredAtNs ?? Clock::nowNs(),
            participantId: $participantId,
            checkpoint: $checkpoint,
            data: $data,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $eventData = $data['data'] ?? [];

        if (! is_array($eventData)) {
            throw new RaceProofException('Timeline event data must be an object.');
        }

        /** @var array<string, bool|float|int|string|null> $validatedData */
        $validatedData = [];

        foreach ($eventData as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new RaceProofException('Timeline event data must contain scalar JSON values with string keys.');
            }

            $validatedData[$key] = $value;
        }

        return new self(
            schemaVersion: self::requiredInteger($data, 'schema_version'),
            eventId: self::requiredString($data, 'event_id'),
            runId: self::requiredString($data, 'run_id'),
            type: self::requiredString($data, 'type'),
            occurredAtNs: self::requiredInteger($data, 'occurred_at_ns'),
            participantId: self::optionalString($data, 'participant_id'),
            checkpoint: self::optionalString($data, 'checkpoint'),
            data: $validatedData,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'event_id' => $this->eventId,
            'run_id' => $this->runId,
            'type' => $this->type,
            'occurred_at_ns' => $this->occurredAtNs,
            'participant_id' => $this->participantId,
            'checkpoint' => $this->checkpoint,
            'data' => (object) $this->data,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RaceProofException("Timeline field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new RaceProofException("Timeline field [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requiredInteger(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new RaceProofException("Timeline field [{$key}] must be an integer.");
        }

        return $value;
    }
}
