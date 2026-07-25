<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\RaceProofException;

final readonly class RaceReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<int, int>  $statuses
     * @param  list<ParticipantReport>  $participants
     * @param  list<array{
     *     type:string,
     *     occurred_at_ns:int,
     *     participant_id:string|null,
     *     checkpoint:string|null,
     *     data:array<string, bool|float|int|string|null>
     * }>  $timelineEvents
     * @param  list<string>  $timelineWarnings
     */
    public function __construct(
        public int $schemaVersion,
        public string $runId,
        public string $outcome,
        public int $expectedParticipants,
        public int $completedParticipants,
        public int $failedParticipants,
        public array $statuses,
        public float $startSpreadMs,
        public float $durationMs,
        public bool $timedOut,
        public ?string $artifactPath,
        public array $participants,
        public ?string $coordinationSummary,
        public int $timelineEventCount,
        public array $timelineEvents,
        public bool $timelineEventsTruncated,
        public int $timelineWarningCount,
        public array $timelineWarnings,
        public bool $timelineWarningsTruncated,
    ) {
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new RaceProofException("Unsupported report schema version [{$schemaVersion}].");
        }

        if (! in_array($outcome, ['passed', 'failed', 'timed_out'], true)) {
            throw new RaceProofException("Unsupported race report outcome [{$outcome}].");
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'run' => [
                'run_id' => $this->runId,
                'outcome' => $this->outcome,
                'expected_participants' => $this->expectedParticipants,
                'completed_participants' => $this->completedParticipants,
                'failed_participants' => $this->failedParticipants,
                'statuses' => (object) $this->statuses,
                'start_spread_ms' => $this->startSpreadMs,
                'duration_ms' => $this->durationMs,
                'timed_out' => $this->timedOut,
                'artifact_path' => $this->artifactPath,
            ],
            'participants' => $this->participants,
            'coordination_summary' => $this->coordinationSummary,
            'timeline' => [
                'event_count' => $this->timelineEventCount,
                'events' => $this->timelineEvents,
                'events_truncated' => $this->timelineEventsTruncated,
                'warning_count' => $this->timelineWarningCount,
                'warnings' => $this->timelineWarnings,
                'warnings_truncated' => $this->timelineWarningsTruncated,
            ],
        ];
    }
}
