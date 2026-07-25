<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Reports\ParticipantReport;
use RaceProof\Laravel\Reports\RaceReport;

final class ReportModelTest extends TestCase
{
    public function test_participant_outcome_helpers_distinguish_failures_from_infrastructure_errors(): void
    {
        $success = $this->participant(ParticipantReport::OUTCOME_SUCCESS);
        $http = $this->participant(ParticipantReport::OUTCOME_HTTP_FAILURE);
        $exception = $this->participant(ParticipantReport::OUTCOME_APPLICATION_EXCEPTION);

        self::assertFalse($success->failed());
        self::assertFalse($success->error());
        self::assertTrue($http->failed());
        self::assertFalse($http->error());
        self::assertTrue($exception->failed());
        self::assertTrue($exception->error());
        self::assertSame('success', $success->jsonSerialize()['outcome']);
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidModels(): iterable
    {
        yield 'participant outcome' => [
            static fn () => new ParticipantReport('p1', 'unknown', null, 0, 0, 0.0),
        ];
        yield 'schema version' => [
            static fn () => self::report(schemaVersion: 2),
        ];
        yield 'run outcome' => [
            static fn () => self::report(outcome: 'unknown'),
        ];
    }

    #[DataProvider('invalidModels')]
    public function test_models_reject_unknown_versioned_values(callable $model): void
    {
        $this->expectException(RaceProofException::class);

        $model();
    }

    private function participant(string $outcome): ParticipantReport
    {
        return new ParticipantReport('p1', $outcome, 200, 1, 2, 0.000001);
    }

    private static function report(int $schemaVersion = 1, string $outcome = 'passed'): RaceReport
    {
        return new RaceReport(
            schemaVersion: $schemaVersion,
            runId: str_repeat('a', 32),
            outcome: $outcome,
            expectedParticipants: 2,
            completedParticipants: 2,
            failedParticipants: 0,
            statuses: [200 => 2],
            startSpreadMs: 0.0,
            durationMs: 1.0,
            timedOut: false,
            artifactPath: null,
            participants: [],
            coordinationSummary: null,
            timelineEventCount: 0,
            timelineWarningCount: 0,
            timelineWarnings: [],
            timelineWarningsTruncated: false,
        );
    }
}
