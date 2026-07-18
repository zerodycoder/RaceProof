<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\Input;

final readonly class ParticipantResult implements JsonSerializable
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $runId,
        public string $participantId,
        public ?int $status,
        public int $startedAtNs,
        public int $finishedAtNs,
        public string $body = '',
        public array $headers = [],
        public ?string $exceptionClass = null,
        public ?string $exceptionMessage = null,
        public ?string $workerError = null,
    ) {}

    public static function workerFailure(string $runId, string $participantId, string $message, ?int $startedAtNs = null): self
    {
        $now = Clock::nowNs();

        return new self(
            runId: $runId,
            participantId: $participantId,
            status: null,
            startedAtNs: $startedAtNs ?? $now,
            finishedAtNs: $now,
            workerError: $message,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            runId: Input::string($data, 'run_id'),
            participantId: Input::string($data, 'participant_id'),
            status: Input::optionalInteger($data, 'status'),
            startedAtNs: Input::integer($data, 'started_at_ns'),
            finishedAtNs: Input::integer($data, 'finished_at_ns'),
            body: array_key_exists('body', $data) ? Input::string($data, 'body') : '',
            headers: Input::stringMap($data, 'headers'),
            exceptionClass: Input::optionalString($data, 'exception_class'),
            exceptionMessage: Input::optionalString($data, 'exception_message'),
            workerError: Input::optionalString($data, 'worker_error'),
        );
    }

    public function durationMs(): float
    {
        return ($this->finishedAtNs - $this->startedAtNs) / 1_000_000;
    }

    public function successful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 400;
    }

    public function serverError(): bool
    {
        return $this->status !== null && $this->status >= 500;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            'participant_id' => $this->participantId,
            'status' => $this->status,
            'started_at_ns' => $this->startedAtNs,
            'finished_at_ns' => $this->finishedAtNs,
            'duration_ms' => $this->durationMs(),
            'body' => $this->body,
            'headers' => $this->headers,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'worker_error' => $this->workerError,
        ];
    }
}
