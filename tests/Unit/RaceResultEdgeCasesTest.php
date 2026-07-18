<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Exceptions\RaceAssertionFailed;
use RaceProof\Laravel\Results\RaceResult;

final class RaceResultEdgeCasesTest extends TestCase
{
    public function test_it_exposes_participant_queries_empty_timings_and_json_evidence(): void
    {
        $runId = str_repeat('e', 32);
        $failed = new ParticipantResult($runId, 'p2', 500, 2_000_000, 3_000_000);
        $result = new RaceResult($runId, 2, [
            new ParticipantResult($runId, 'p1', 200, 1_000_000, 2_000_000),
            $failed,
        ], artifactPath: '/tmp/evidence');

        self::assertSame([$failed], $result->failed());
        self::assertSame($failed, $result->participant('p2'));
        self::assertNull($result->participant('p3'));
        self::assertSame('/tmp/evidence', $result->jsonSerialize()['artifact_path']);

        $empty = new RaceResult($runId, 2, []);
        self::assertSame(0.0, $empty->startSpreadMs());
        self::assertSame(0.0, $empty->durationMs());
    }

    /** @return iterable<string, array{RaceResult, string, string}> */
    public static function failingAssertions(): iterable
    {
        $runId = str_repeat('f', 32);

        yield 'missing participants' => [new RaceResult($runId, 2, []), 'assertAllFinished', 'received 0'];
        yield 'worker failure' => [new RaceResult($runId, 1, [
            ParticipantResult::workerFailure($runId, 'p1', 'crashed'),
        ]), 'assertNoWorkerFailures', 'p1: crashed'];
        yield 'server error' => [new RaceResult($runId, 1, [
            new ParticipantResult($runId, 'p1', 500, 1, 2),
        ]), 'assertNoServerErrors', 'observed 1'];
        yield 'success count' => [new RaceResult($runId, 1, []), 'assertExactlySuccessful', 'Expected 1'];
        yield 'start spread' => [new RaceResult($runId, 2, [
            new ParticipantResult($runId, 'p1', 200, 1, 2),
            new ParticipantResult($runId, 'p2', 200, 2_000_001, 2_000_002),
        ]), 'assertStartSpreadBelow', 'below 1.00 ms'];
        yield 'timeout' => [new RaceResult($runId, 1, [], true), 'assertNoTimeouts', 'timed out'];
        yield 'invariant' => [new RaceResult($runId, 1, []), 'assertInvariant', 'custom invariant'];
    }

    #[DataProvider('failingAssertions')]
    public function test_assertions_explain_failures(RaceResult $result, string $method, string $message): void
    {
        $this->expectException(RaceAssertionFailed::class);
        $this->expectExceptionMessage($message);

        match ($method) {
            'assertExactlySuccessful' => $result->assertExactlySuccessful(1),
            'assertStartSpreadBelow' => $result->assertStartSpreadBelow(1),
            'assertInvariant' => $result->assertInvariant(static fn (): bool => false, $message),
            default => $result->{$method}(),
        };
    }
}
