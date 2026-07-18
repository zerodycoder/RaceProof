<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Results;

use JsonSerializable;
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
        ];
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RaceAssertionFailed($message);
        }
    }
}
