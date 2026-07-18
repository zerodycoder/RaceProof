<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Exceptions\RaceAssertionFailed;
use RaceProof\Laravel\Results\RaceResult;

final class RaceResultTest extends TestCase
{
    public function test_it_aggregates_and_asserts_participant_results(): void
    {
        $runId = str_repeat('a', 32);
        $result = new RaceResult($runId, 2, [
            new ParticipantResult($runId, 'p1', 201, 1_000_000, 2_000_000),
            new ParticipantResult($runId, 'p2', 409, 2_000_000, 4_000_000),
        ]);

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoServerErrors()
            ->assertStatusCount(201, 1)
            ->assertStatusCount(409, 1)
            ->assertExactlySuccessful(1)
            ->assertStartSpreadBelow(2)
            ->assertNoTimeouts()
            ->assertInvariant(fn (): bool => true, 'must hold');

        self::assertSame([201 => 1, 409 => 1], $result->statuses());
        self::assertSame(1.0, $result->startSpreadMs());
        self::assertSame(3.0, $result->durationMs());
    }

    public function test_it_throws_a_clear_assertion_failure(): void
    {
        $this->expectException(RaceAssertionFailed::class);
        $this->expectExceptionMessage('Expected status 201 1 time(s); observed 0.');

        (new RaceResult(str_repeat('b', 32), 2, []))->assertStatusCount(201, 1);
    }
}
