<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Studio;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\RaceProofException;

/**
 * @internal Studio reads this value from a validated archived report.
 */
final readonly class StudioRun implements JsonSerializable
{
    /**
     * @param  array<int, int>  $statuses
     * @param  list<StudioParticipant>  $participants
     * @param  list<StudioEvent>  $events
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        public string $runId,
        public string $capturedAt,
        public string $outcome,
        public int $expectedParticipants,
        public int $completedParticipants,
        public int $failedParticipants,
        public array $statuses,
        public float $startSpreadMs,
        public float $durationMs,
        public bool $timedOut,
        public array $participants,
        public ?string $coordinationSummary,
        public int $timelineEventCount,
        public array $events,
        public bool $eventsTruncated,
        public int $warningCount,
        public array $warnings,
        public bool $warningsTruncated,
        private array $document,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document): self
    {
        if (self::integer($document, 'archive_schema') !== 1) {
            throw new RaceProofException('Unsupported Studio archive schema.');
        }

        $capturedAt = self::string($document, 'captured_at');
        $report = self::map($document, 'report');

        if (self::integer($report, 'schema_version') !== 1) {
            throw new RaceProofException('Unsupported Studio report schema.');
        }

        $run = self::map($report, 'run');
        $runId = self::string($run, 'run_id');

        if (preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new RaceProofException('Studio report contains an invalid run ID.');
        }

        $outcome = self::string($run, 'outcome');

        if (! in_array($outcome, ['passed', 'failed', 'timed_out'], true)) {
            throw new RaceProofException('Studio report contains an invalid outcome.');
        }

        $statuses = [];
        $statusValues = $run['statuses'] ?? null;

        if (! is_array($statusValues)) {
            throw new RaceProofException('Studio report statuses must be an object.');
        }

        foreach ($statusValues as $status => $count) {
            $statusCode = (string) $status;

            if (! ctype_digit($statusCode) || ! is_int($count) || $count < 0) {
                throw new RaceProofException('Studio report contains an invalid status count.');
            }

            $statuses[(int) $statusCode] = $count;
        }

        $participants = [];

        foreach (self::list($report, 'participants') as $participant) {
            if (! is_array($participant)) {
                throw new RaceProofException('Studio report contains an invalid participant.');
            }

            $participant = self::object($participant, 'participant');
            $participantId = self::string($participant, 'participant_id');
            $participantOutcome = self::string($participant, 'outcome');
            $status = $participant['status'] ?? null;
            $exceptionClass = $participant['exception_class'] ?? null;
            $execution = $participant['execution'] ?? 'http';
            $attempts = $participant['attempts'] ?? 1;
            $jobClass = self::optionalString($participant['job_class'] ?? null);
            $queueConnection = self::optionalString($participant['queue_connection'] ?? null);
            $queueName = self::optionalString($participant['queue_name'] ?? null);

            if (preg_match('/^p[1-9][0-9]{0,2}$/D', $participantId) !== 1) {
                throw new RaceProofException('Studio report contains an invalid participant ID.');
            }

            if (! in_array($participantOutcome, [
                'success',
                'http_failure',
                'application_exception',
                'worker_error',
                'missing',
            ], true)) {
                throw new RaceProofException('Studio report contains an invalid participant outcome.');
            }

            if ($status !== null && ! is_int($status)) {
                throw new RaceProofException('Studio report contains an invalid response status.');
            }

            if ($exceptionClass !== null && ! is_string($exceptionClass)) {
                throw new RaceProofException('Studio report contains an invalid exception class.');
            }

            if (
                ! is_string($execution)
                || ! in_array($execution, ['http', 'queue'], true)
                || ! is_int($attempts)
                || $attempts < 0
                || $attempts > 5
            ) {
                throw new RaceProofException('Studio report contains invalid participant execution metadata.');
            }

            if (
                $execution === 'queue'
                && (
                    $jobClass === null
                    || $queueConnection === null
                    || $queueName === null
                    || preg_match('/^raceproof:[a-f0-9]{32}:p[1-9][0-9]{0,2}$/D', $queueName) !== 1
                )
            ) {
                throw new RaceProofException('Studio report contains incomplete queue participant metadata.');
            }

            $headers = [];

            foreach (self::map($participant, 'headers') as $name => $value) {
                if (! is_string($value)) {
                    throw new RaceProofException('Studio report contains an invalid response header.');
                }

                $headers[$name] = $value;
            }

            $participants[] = new StudioParticipant(
                id: $participantId,
                outcome: $participantOutcome,
                status: $status,
                durationMs: self::number($participant, 'duration_ms'),
                diagnostic: self::string($participant, 'diagnostic'),
                body: self::string($participant, 'body'),
                bodyTruncated: self::boolean($participant, 'body_truncated'),
                headers: $headers,
                headersTruncated: self::boolean($participant, 'headers_truncated'),
                exceptionClass: $exceptionClass,
                execution: $execution,
                attempts: $attempts,
                jobClass: $jobClass,
                queueConnection: $queueConnection,
                queueName: $queueName,
            );
        }

        $timeline = self::map($report, 'timeline');
        $events = [];

        foreach (self::list($timeline, 'events') as $event) {
            if (! is_array($event)) {
                throw new RaceProofException('Studio report contains an invalid timeline event.');
            }

            $event = self::object($event, 'timeline event');
            $participantId = $event['participant_id'] ?? null;
            $checkpoint = $event['checkpoint'] ?? null;

            if ($participantId !== null && ! is_string($participantId)) {
                throw new RaceProofException('Studio event contains an invalid participant ID.');
            }

            if ($checkpoint !== null && ! is_string($checkpoint)) {
                throw new RaceProofException('Studio event contains an invalid checkpoint.');
            }

            $data = [];

            foreach (self::map($event, 'data') as $key => $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new RaceProofException('Studio event contains invalid data.');
                }

                $data[$key] = $value;
            }

            $events[] = new StudioEvent(
                type: self::string($event, 'type'),
                occurredAtNs: self::integer($event, 'occurred_at_ns'),
                participantId: $participantId,
                checkpoint: $checkpoint,
                data: $data,
            );
        }

        $warnings = [];

        foreach (self::list($timeline, 'warnings') as $warning) {
            if (! is_string($warning)) {
                throw new RaceProofException('Studio report contains an invalid timeline warning.');
            }

            $warnings[] = $warning;
        }

        $coordinationSummary = $report['coordination_summary'] ?? null;

        if ($coordinationSummary !== null && ! is_string($coordinationSummary)) {
            throw new RaceProofException('Studio report contains an invalid coordination summary.');
        }

        return new self(
            runId: $runId,
            capturedAt: $capturedAt,
            outcome: $outcome,
            expectedParticipants: self::integer($run, 'expected_participants'),
            completedParticipants: self::integer($run, 'completed_participants'),
            failedParticipants: self::integer($run, 'failed_participants'),
            statuses: $statuses,
            startSpreadMs: self::number($run, 'start_spread_ms'),
            durationMs: self::number($run, 'duration_ms'),
            timedOut: self::boolean($run, 'timed_out'),
            participants: $participants,
            coordinationSummary: $coordinationSummary,
            timelineEventCount: self::integer($timeline, 'event_count'),
            events: $events,
            eventsTruncated: self::boolean($timeline, 'events_truncated'),
            warningCount: self::integer($timeline, 'warning_count'),
            warnings: $warnings,
            warningsTruncated: self::boolean($timeline, 'warnings_truncated'),
            document: $document,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function map(array $data, string $key): array
    {
        return self::object($data[$key] ?? null, $key);
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new RaceProofException("Studio field [{$context}] must be an object.");
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RaceProofException("Studio field [{$context}] must use string keys.");
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RaceProofException("Studio field [{$key}] must be a list.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RaceProofException("Studio field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new RaceProofException("Studio field [{$key}] must be a non-negative integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function number(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new RaceProofException("Studio field [{$key}] must be numeric.");
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < 0) {
            throw new RaceProofException("Studio field [{$key}] must be a finite non-negative number.");
        }

        return $number;
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new RaceProofException("Studio field [{$key}] must be a boolean.");
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new RaceProofException('Studio report contains invalid queue metadata.');
        }

        return $value;
    }
}
