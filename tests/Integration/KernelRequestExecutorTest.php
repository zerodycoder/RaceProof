<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Support\Facades\Route;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;

final class KernelRequestExecutorTest extends TestCase
{
    public function test_it_executes_a_json_request_through_the_real_http_kernel(): void
    {
        Route::post('/raceproof/echo', fn () => response()->json([
            'value' => request()->integer('value'),
        ], 201)->header('X-Request-Id', 'request-123'));

        $plan = new RacePlan(
            str_repeat('c', 32),
            2,
            new RequestSpec('POST', '/raceproof/echo', ['value' => 42]),
        );

        $result = $this->app->make(RequestExecutor::class)->execute(
            $plan,
            new ParticipantContext($plan->runId, 'p1'),
        );

        self::assertSame(201, $result->status);
        self::assertJsonStringEqualsJsonString('{"value":42}', $result->body);
        self::assertSame('request-123', $result->headers['x-request-id']);
        self::assertNull($result->workerError);
    }
}
