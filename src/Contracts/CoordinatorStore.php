<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Results\RaceTimeline;

interface CoordinatorStore
{
    public function createRun(RacePlan $plan): void;

    public function plan(string $runId): RacePlan;

    public function markReady(string $runId, string $participantId): void;

    public function readyCount(string $runId): int;

    public function releaseStart(string $runId): void;

    public function waitForStart(string $runId, int $timeoutMs): void;

    public function reachCheckpoint(string $runId, string $participantId, string $checkpoint): void;

    public function checkpointCount(string $runId, string $checkpoint): int;

    public function releaseCheckpoint(string $runId, string $checkpoint): void;

    public function waitForCheckpoint(string $runId, string $checkpoint, int $timeoutMs): void;

    public function storeResult(ParticipantResult $result): void;

    /** @return list<ParticipantResult> */
    public function results(string $runId): array;

    public function recordEvent(TimelineEvent $event): void;

    public function timeline(string $runId): RaceTimeline;

    public function cleanup(string $runId): void;

    public function basePath(): string;
}
