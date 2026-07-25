<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Results;

use JsonSerializable;
use RaceProof\Laravel\Contracts\Reporter;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Exceptions\RaceAssertionFailed;

final readonly class RaceResult implements JsonSerializable
{
    /** @param list<ParticipantResult> $participants */
    public function __construct(
        public string $runId,
        public int $expectedParticipants,
        public array $participants,
        public bool $timedOut = false,
        public ?string $artifactPath = null,
        public ?RaceTimeline $timeline = null,
    ) {}

    /** @return list<ParticipantResult> */
    public function successful(): array
    {
        return array_values(array_filter($this->participants, fn (ParticipantResult $result): bool => $result->successful()));
    }

    /** @return list<ParticipantResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->participants, fn (ParticipantResult $result): bool => ! $result->successful()));
    }

    /** @return array<int, int> */
    public function statuses(): array
    {
        $statuses = [];

        foreach ($this->participants as $result) {
            if ($result->status !== null) {
                $statuses[$result->status] = ($statuses[$result->status] ?? 0) + 1;
            }
        }

        ksort($statuses);

        return $statuses;
    }

    public function participant(string $participantId): ?ParticipantResult
    {
        foreach ($this->participants as $result) {
            if ($result->participantId === $participantId) {
                return $result;
            }
        }

        return null;
    }

    public function startSpreadMs(): float
    {
        if (count($this->participants) < 2) {
            return 0.0;
        }

        $starts = array_map(fn (ParticipantResult $result): int => $result->startedAtNs, $this->participants);

        return (max($starts) - min($starts)) / 1_000_000;
    }

    public function durationMs(): float
    {
        if ($this->participants === []) {
            return 0.0;
        }

        $starts = array_map(fn (ParticipantResult $result): int => $result->startedAtNs, $this->participants);
        $finishes = array_map(fn (ParticipantResult $result): int => $result->finishedAtNs, $this->participants);

        return (max($finishes) - min($starts)) / 1_000_000;
    }

    public function failureReport(): string
    {
        $failed = $this->failed();
        $lines = [
            "RaceProof run {$this->runId}",
            sprintf(
                'Participants: %d/%d finished; %d failed; timed out: %s.',
                count($this->participants),
                $this->expectedParticipants,
                count($failed),
                $this->timedOut ? 'yes' : 'no',
            ),
            sprintf('Timing: %.2f ms total; %.2f ms start spread.', $this->durationMs(), $this->startSpreadMs()),
        ];

        $statuses = [];

        foreach ($this->statuses() as $status => $count) {
            $statuses[] = "{$status} x {$count}";
        }

        if ($statuses !== []) {
            $lines[] = 'Statuses: '.implode(', ', $statuses).'.';
        }

        $coordination = $this->coordinationSummary();

        if ($coordination !== null) {
            $lines[] = $coordination;
        }

        foreach ($failed as $participant) {
            $reason = match (true) {
                $participant->workerError !== null => $participant->workerError,
                $participant->exceptionClass !== null => 'application exception '.$participant->exceptionClass,
                $participant->status !== null => 'HTTP '.$participant->status,
                default => 'no response evidence',
            };
            $lines[] = "Failure {$participant->participantId}: ".$this->compactDiagnostic($reason);
        }

        if ($this->timeline !== null) {
            $lines[] = 'Timeline: '.count($this->timeline->events).' event(s); '.count($this->timeline->warnings).' warning(s).';
        }

        $lines[] = 'Artifacts: '.($this->artifactPath ?? 'none (successful run was cleaned)').'.';

        return implode("\n", $lines);
    }

    public function report(Reporter $reporter): string
    {
        return $reporter->report($this);
    }

    public function assertAllFinished(): self
    {
        $this->assert(
            count($this->participants) === $this->expectedParticipants,
            "Expected {$this->expectedParticipants} participant results, received ".count($this->participants).'.',
        );

        return $this;
    }

    public function assertNoWorkerFailures(): self
    {
        $failures = array_values(array_filter(
            $this->participants,
            fn (ParticipantResult $result): bool => $result->workerError !== null,
        ));

        $this->assert($failures === [], 'Worker failures: '.implode('; ', array_map(
            fn (ParticipantResult $result): string => "{$result->participantId}: {$result->workerError}",
            $failures,
        )));

        return $this;
    }

    public function assertNoServerErrors(): self
    {
        $count = count(array_filter($this->participants, fn (ParticipantResult $result): bool => $result->serverError()));
        $this->assert($count === 0, "Expected no server errors; observed {$count}.");

        return $this;
    }

    public function assertStatusCount(int $status, int $expected): self
    {
        $actual = $this->statuses()[$status] ?? 0;
        $this->assert($actual === $expected, "Expected status {$status} {$expected} time(s); observed {$actual}.");

        return $this;
    }

    public function assertExactlySuccessful(int $expected): self
    {
        $actual = count($this->successful());
        $this->assert($actual === $expected, "Expected {$expected} successful response(s); observed {$actual}.");

        return $this;
    }

    public function assertStartSpreadBelow(float $milliseconds): self
    {
        $actual = $this->startSpreadMs();
        $this->assert($actual < $milliseconds, sprintf(
            'Expected start spread below %.2f ms; observed %.2f ms.',
            $milliseconds,
            $actual,
        ));

        return $this;
    }

    public function assertNoTimeouts(): self
    {
        $this->assert(! $this->timedOut, 'The race run timed out.');

        return $this;
    }

    public function assertInvariant(callable $invariant, string $message): self
    {
        $this->assert((bool) $invariant(), $message);

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            'expected_participants' => $this->expectedParticipants,
            'participants' => $this->participants,
            'statuses' => $this->statuses(),
            'start_spread_ms' => $this->startSpreadMs(),
            'duration_ms' => $this->durationMs(),
            'timed_out' => $this->timedOut,
            'artifact_path' => $this->artifactPath,
            'timeline' => $this->timeline,
        ];
    }

    private function coordinationSummary(): ?string
    {
        if ($this->timeline === null) {
            return null;
        }

        $ready = [];
        $checkpoints = [];

        foreach ($this->timeline->events as $event) {
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

        $summary = ['ready '.count($ready).'/'.$this->expectedParticipants];

        foreach ($checkpoints as $checkpoint => $state) {
            $summary[] = sprintf(
                '%s %d/%d %s',
                $checkpoint,
                count($state['participants']),
                $this->expectedParticipants,
                $state['released'] ? 'released' : 'blocked',
            );
        }

        return 'Coordination: '.implode('; ', $summary).'.';
    }

    private function compactDiagnostic(string $value): string
    {
        $compact = trim((string) preg_replace('/\s+/', ' ', $value));

        return strlen($compact) <= 240 ? $compact : substr($compact, 0, 228).' [truncated]';
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RaceAssertionFailed($message."\n\n".$this->failureReport());
        }
    }
}
