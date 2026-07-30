<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\RaceProofException;

final readonly class ParticipantReport implements JsonSerializable
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_HTTP_FAILURE = 'http_failure';

    public const OUTCOME_APPLICATION_EXCEPTION = 'application_exception';

    public const OUTCOME_WORKER_ERROR = 'worker_error';

    public const OUTCOME_MISSING = 'missing';

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $participantId,
        public string $outcome,
        public ?int $status,
        public int $startedAtNs,
        public int $finishedAtNs,
        public float $durationMs,
        public string $diagnostic = '',
        public string $body = '',
        public bool $bodyTruncated = false,
        public array $headers = [],
        public bool $headersTruncated = false,
        public ?string $exceptionClass = null,
        public string $execution = 'http',
        public int $attempts = 1,
        public ?string $jobClass = null,
        public ?string $queueConnection = null,
        public ?string $queueName = null,
    ) {
        if (! in_array($outcome, self::outcomes(), true)) {
            throw new RaceProofException("Unsupported participant report outcome [{$outcome}].");
        }

        if (! in_array($execution, ['http', 'queue'], true) || $attempts < 0 || $attempts > 5) {
            throw new RaceProofException('Participant report execution metadata is invalid.');
        }

        if (
            $execution === 'queue'
            && ($jobClass === null || $queueConnection === null || $queueName === null)
        ) {
            throw new RaceProofException('Queue participant report metadata is incomplete.');
        }
    }

    public function failed(): bool
    {
        return $this->outcome !== self::OUTCOME_SUCCESS;
    }

    public function error(): bool
    {
        return in_array($this->outcome, [
            self::OUTCOME_APPLICATION_EXCEPTION,
            self::OUTCOME_WORKER_ERROR,
            self::OUTCOME_MISSING,
        ], true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'participant_id' => $this->participantId,
            'outcome' => $this->outcome,
            'status' => $this->status,
            'started_at_ns' => $this->startedAtNs,
            'finished_at_ns' => $this->finishedAtNs,
            'duration_ms' => $this->durationMs,
            'diagnostic' => $this->diagnostic,
            'body' => $this->body,
            'body_truncated' => $this->bodyTruncated,
            'headers' => (object) $this->headers,
            'headers_truncated' => $this->headersTruncated,
            'exception_class' => $this->exceptionClass,
        ];

        if ($this->execution === 'queue') {
            $data['execution'] = $this->execution;
            $data['attempts'] = $this->attempts;
            $data['job_class'] = $this->jobClass;
            $data['queue_connection'] = $this->queueConnection;
            $data['queue_name'] = $this->queueName;
        }

        return $data;
    }

    /** @return list<string> */
    private static function outcomes(): array
    {
        return [
            self::OUTCOME_SUCCESS,
            self::OUTCOME_HTTP_FAILURE,
            self::OUTCOME_APPLICATION_EXCEPTION,
            self::OUTCOME_WORKER_ERROR,
            self::OUTCOME_MISSING,
        ];
    }
}
