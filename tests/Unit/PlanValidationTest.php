<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final class PlanValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedPlans(): iterable
    {
        $valid = [
            'run_id' => str_repeat('a', 32),
            'participants' => 2,
            'request' => [
                'method' => 'POST',
                'uri' => '/checkout',
                'payload' => [],
                'headers' => [],
                'cookies' => [],
                'json' => true,
            ],
            'checkpoints' => [],
        ];

        yield 'non-string run id' => [array_replace($valid, ['run_id' => 123])];
        yield 'non-integer participants' => [array_replace($valid, ['participants' => '2'])];
        yield 'non-object request' => [array_replace($valid, ['request' => 'POST /checkout'])];
        yield 'non-string header' => [array_replace_recursive($valid, ['request' => ['headers' => ['X-Test' => 123]]])];
        yield 'non-string checkpoint' => [array_replace($valid, ['checkpoints' => [123]])];
        yield 'participant specs list' => [array_replace($valid, ['participant_specs' => [['payload' => []]]])];
    }

    /** @param array<string, mixed> $plan */
    #[DataProvider('malformedPlans')]
    public function test_it_rejects_malformed_json_boundaries(array $plan): void
    {
        $this->expectException(InvalidRacePlan::class);

        RacePlan::fromArray($plan);
    }
}
