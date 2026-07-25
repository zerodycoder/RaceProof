<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\SensitiveDataRedactor;

final readonly class RaceReportFactory
{
    public function __construct(
        private Config $config,
        private SensitiveDataRedactor $redactor,
    ) {}

    public function make(RaceResult $result): RaceReport
    {
        $participants = $this->participants($result);
        $failedParticipants = count(array_filter(
            $participants,
            static fn (ParticipantReport $participant): bool => $participant->failed(),
        ));
        $warnings = $result->timeline === null ? [] : $result->timeline->warnings;
        $events = $result->timeline === null ? [] : $result->timeline->events;
        $eventLimit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.timeline_event_limit',
            500,
        ));
        $redactedEventKeys = array_map(
            static fn (string $key): string => strtolower($key),
            ConfigValue::stringList($this->config, 'raceproof.capture.redact_keys'),
        );
        $reportedEvents = [];

        foreach (array_slice($events, 0, $eventLimit) as $event) {
            $eventDataLimit = max(0, ConfigValue::integer(
                $this->config,
                'raceproof.reporting.timeline_event_data_limit',
                16,
            ));
            $data = [];

            foreach (array_slice($event->data, 0, $eventDataLimit, true) as $key => $value) {
                $data[$this->diagnostic($key)] = in_array(strtolower($key), $redactedEventKeys, true)
                    ? '[REDACTED]'
                    : (is_string($value) ? $this->diagnostic($value) : $value);
            }

            $reportedEvents[] = [
                'type' => $event->type,
                'occurred_at_ns' => $event->occurredAtNs,
                'participant_id' => $event->participantId,
                'checkpoint' => $event->checkpoint,
                'data' => $data,
            ];
        }
        $warningLimit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.timeline_warning_limit',
            100,
        ));
        $reportedWarnings = [];

        foreach (array_slice($warnings, 0, $warningLimit) as $warning) {
            $reportedWarnings[] = $this->diagnostic($warning);
        }

        $outcome = match (true) {
            $result->timedOut => 'timed_out',
            $failedParticipants > 0 || count($result->participants) !== $result->expectedParticipants => 'failed',
            default => 'passed',
        };

        return new RaceReport(
            schemaVersion: RaceReport::SCHEMA_VERSION,
            runId: $result->runId,
            outcome: $outcome,
            expectedParticipants: $result->expectedParticipants,
            completedParticipants: count($result->participants),
            failedParticipants: $failedParticipants,
            statuses: $result->statuses(),
            startSpreadMs: $result->startSpreadMs(),
            durationMs: $result->durationMs(),
            timedOut: $result->timedOut,
            artifactPath: $result->artifactPath === null ? null : $this->diagnostic($result->artifactPath),
            participants: $participants,
            coordinationSummary: $this->coordinationSummary($result),
            timelineEventCount: count($events),
            timelineEvents: $reportedEvents,
            timelineEventsTruncated: count($events) > count($reportedEvents),
            timelineWarningCount: count($warnings),
            timelineWarnings: $reportedWarnings,
            timelineWarningsTruncated: count($warnings) > count($reportedWarnings),
        );
    }

    /** @return list<ParticipantReport> */
    private function participants(RaceResult $result): array
    {
        $reported = [];
        $byParticipant = [];

        foreach ($result->participants as $participant) {
            $byParticipant[$participant->participantId] = $participant;
        }

        for ($number = 1; $number <= $result->expectedParticipants; $number++) {
            $participantId = 'p'.$number;
            $reported[] = isset($byParticipant[$participantId])
                ? $this->participant($byParticipant[$participantId])
                : $this->missingParticipant($participantId);
            unset($byParticipant[$participantId]);
        }

        ksort($byParticipant, SORT_NATURAL);

        foreach ($byParticipant as $participant) {
            $reported[] = $this->participant($participant);
        }

        return $reported;
    }

    private function participant(ParticipantResult $participant): ParticipantReport
    {
        $outcome = match (true) {
            $participant->workerError !== null => ParticipantReport::OUTCOME_WORKER_ERROR,
            $participant->exceptionClass !== null => ParticipantReport::OUTCOME_APPLICATION_EXCEPTION,
            $participant->status === null => ParticipantReport::OUTCOME_MISSING,
            $participant->successful() => ParticipantReport::OUTCOME_SUCCESS,
            default => ParticipantReport::OUTCOME_HTTP_FAILURE,
        };
        $diagnostic = match ($outcome) {
            ParticipantReport::OUTCOME_WORKER_ERROR => (string) $participant->workerError,
            ParticipantReport::OUTCOME_APPLICATION_EXCEPTION => trim(
                (string) $participant->exceptionClass.': '.(string) $participant->exceptionMessage,
                ': ',
            ),
            ParticipantReport::OUTCOME_HTTP_FAILURE => $participant->status === null
                ? 'No response status was recorded.'
                : 'HTTP '.$participant->status,
            ParticipantReport::OUTCOME_MISSING => 'No response status was recorded.',
            default => '',
        };
        $bodyLimit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.response_body_bytes',
            4_096,
        ));
        $redactedBody = $this->redactor->redact($participant->body);
        $body = $this->redactor->bounded($participant->body, $bodyLimit);
        [$headers, $headersTruncated] = $this->headers($participant->headers);

        return new ParticipantReport(
            participantId: $participant->participantId,
            outcome: $outcome,
            status: $participant->status,
            startedAtNs: $participant->startedAtNs,
            finishedAtNs: $participant->finishedAtNs,
            durationMs: $participant->durationMs(),
            diagnostic: $this->diagnostic($diagnostic),
            body: $body,
            bodyTruncated: strlen($redactedBody) > strlen($body),
            headers: $headers,
            headersTruncated: $headersTruncated,
            exceptionClass: $participant->exceptionClass === null
                ? null
                : $this->diagnostic($participant->exceptionClass),
        );
    }

    private function missingParticipant(string $participantId): ParticipantReport
    {
        return new ParticipantReport(
            participantId: $participantId,
            outcome: ParticipantReport::OUTCOME_MISSING,
            status: null,
            startedAtNs: 0,
            finishedAtNs: 0,
            durationMs: 0.0,
            diagnostic: 'No participant result was recorded.',
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{array<string, string>, bool}
     */
    private function headers(array $headers): array
    {
        $headerLimit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.header_limit',
            32,
        ));
        $redactedNames = array_map(
            static fn (string $name): string => strtolower($name),
            ConfigValue::stringList($this->config, 'raceproof.capture.redact_headers'),
        );
        $reported = [];
        $count = 0;

        foreach ($headers as $name => $value) {
            if ($count >= $headerLimit) {
                break;
            }

            $safeName = $this->diagnostic($name);
            $reported[$safeName] = in_array(strtolower($name), $redactedNames, true)
                ? '[REDACTED]'
                : $this->diagnostic($value);
            $count++;
        }

        return [$reported, count($headers) > $count];
    }

    private function diagnostic(string $value): string
    {
        $limit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.diagnostic_text_bytes',
            4_096,
        ));

        return $this->redactor->bounded($value, $limit);
    }

    private function coordinationSummary(RaceResult $result): ?string
    {
        if ($result->timeline === null) {
            return null;
        }

        $ready = [];
        $checkpoints = [];

        foreach ($result->timeline->events as $event) {
            if ($event->type === 'participant.ready' && $event->participantId !== null) {
                $ready[$event->participantId] = true;
            }

            if ($event->checkpoint === null) {
                continue;
            }

            $checkpoints[$event->checkpoint] ??= ['participants' => [], 'released' => false];

            if ($event->type === 'checkpoint.reached' && $event->participantId !== null) {
                $checkpoints[$event->checkpoint]['participants'][$event->participantId] = true;
            }

            if ($event->type === 'checkpoint.released') {
                $checkpoints[$event->checkpoint]['released'] = true;
            }
        }

        $summary = ['ready '.count($ready).'/'.$result->expectedParticipants];

        foreach ($checkpoints as $checkpoint => $state) {
            $summary[] = sprintf(
                '%s %d/%d %s',
                $checkpoint,
                count($state['participants']),
                $result->expectedParticipants,
                $state['released'] ? 'released' : 'blocked',
            );
        }

        return $this->diagnostic(implode('; ', $summary));
    }
}
