<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceAssertionFailed;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Results\RaceTimeline;

final class RaceResultReportTest extends TestCase
{
    public function test_it_renders_concise_participant_coordination_and_artifact_evidence(): void
    {
        $runId = str_repeat('a', 32);
        $timeline = new RaceTimeline($runId, [
            TimelineEvent::make($runId, 'participant.ready', 'p1', occurredAtNs: 1),
            TimelineEvent::make($runId, 'participant.ready', 'p2', occurredAtNs: 2),
            TimelineEvent::make($runId, 'checkpoint.reached', 'p1', 'after-read', occurredAtNs: 3),
            TimelineEvent::make($runId, 'checkpoint.released', checkpoint: 'after-read', occurredAtNs: 4),
            TimelineEvent::make($runId, 'participant.exited', 'p2', data: ['exit_code' => 5], occurredAtNs: 5),
        ], ['Timeline line 6 is malformed and was ignored.']);
        $result = new RaceResult(
            $runId,
            2,
            [
                new ParticipantResult($runId, 'p1', 201, 1_000_000, 2_000_000),
                new ParticipantResult($runId, 'p2', null, 1_500_000, 3_000_000, workerError: 'Worker exited with exit code 5.'),
            ],
            artifactPath: '/tmp/raceproof/'.$runId,
            timeline: $timeline,
        );

        $report = $result->failureReport();

        self::assertStringContainsString('Participants: 2/2 finished; 1 failed', $report);
        self::assertStringContainsString('Coordination: ready 2/2; after-read 1/2 released.', $report);
        self::assertStringContainsString('Failure p2: Worker exited with exit code 5.', $report);
        self::assertStringContainsString('Timeline: 5 event(s); 1 warning(s).', $report);
        self::assertStringContainsString('Artifacts: /tmp/raceproof/'.$runId, $report);

        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['timeline']['schema_version']);
        self::assertCount(5, $json['timeline']['events']);
    }

    public function test_assertion_failures_include_the_actionable_report(): void
    {
        $runId = str_repeat('b', 32);
        $result = new RaceResult($runId, 2, [], artifactPath: '/tmp/raceproof/'.$runId);

        try {
            $result->assertAllFinished();
            self::fail('Expected an assertion failure.');
        } catch (RaceAssertionFailed $exception) {
            self::assertStringContainsString('Expected 2 participant results, received 0.', $exception->getMessage());
            self::assertStringContainsString('RaceProof run '.$runId, $exception->getMessage());
            self::assertStringContainsString('Artifacts: /tmp/raceproof/'.$runId, $exception->getMessage());
        }
    }
}
