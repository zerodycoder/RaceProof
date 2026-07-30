<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\Input;

final readonly class ParticipantResult implements JsonSerializable
{
    /**
     * @internal RaceProof creates participant results from worker evidence.
     *
     * @param  array<string, string>  $headers
     */
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
        public string $execution = 'http',
        public int $attempts = 1,
        public ?string $jobClass = null,
        public ?string $queueConnection = null,
        public ?string $queueName = null,
    ) {
        if (! in_array($execution, ['http', 'queue'], true) || $attempts < 0 || $attempts > 5) {
            throw new RaceProofException('Participant result execution metadata is invalid.');
        }

        if (
            $execution === 'queue'
            && (
                $jobClass === null
                || $jobClass === ''
                || strlen($jobClass) > 255
                || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $jobClass) !== 1
                || $queueConnection === null
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $queueConnection) !== 1
                || $queueName === null
                || preg_match('/^raceproof:[a-f0-9]{32}:p[1-9][0-9]{0,2}$/D', $queueName) !== 1
                || $queueName !== "raceproof:{$runId}:{$participantId}"
            )
        ) {
            throw new RaceProofException('Queue participant result metadata is incomplete or invalid.');
        }
    }

    /** @internal */
    public static function workerFailure(
        string $runId,
        string $participantId,
        string $message,
        ?int $startedAtNs = null,
        ?RacePlan $plan = null,
    ): self {
        $now = Clock::nowNs();
        $queue = $plan?->queue;

        return new self(
            runId: $runId,
            participantId: $participantId,
            status: null,
            startedAtNs: $startedAtNs ?? $now,
            finishedAtNs: $now,
            workerError: $message,
            execution: $queue === null ? 'http' : 'queue',
            attempts: $queue === null ? 1 : 0,
            jobClass: $queue?->jobClassFor($participantId),
            queueConnection: $queue?->connection,
            queueName: $queue?->queueFor($runId, $participantId),
        );
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $data
     */
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
            execution: array_key_exists('execution', $data) ? Input::string($data, 'execution') : 'http',
            attempts: Input::integer($data, 'attempts', 1),
            jobClass: Input::optionalString($data, 'job_class'),
            queueConnection: Input::optionalString($data, 'queue_connection'),
            queueName: Input::optionalString($data, 'queue_name'),
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
            'execution' => $this->execution,
            'attempts' => $this->attempts,
            'job_class' => $this->jobClass,
            'queue_connection' => $this->queueConnection,
            'queue_name' => $this->queueName,
        ];
    }
}
