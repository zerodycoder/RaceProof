<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Mockery;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Execution\KernelRequestExecutor;
use RuntimeException;

final class KernelRequestExecutorEdgesTest extends TestCase
{
    public function test_it_captures_application_exceptions_as_participant_evidence(): void
    {
        $plan = $this->plan(new RequestSpec('POST', '/raceproof/explode'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()->andThrow(new RuntimeException('route exploded'));
        $executor = new KernelRequestExecutor($kernel, $this->app['auth'], $this->app['config']);

        $result = $executor->execute(
            $plan,
            new ParticipantContext($plan->runId, 'p1'),
        );

        self::assertNull($result->status);
        self::assertSame(RuntimeException::class, $result->exceptionClass);
        self::assertSame('route exploded', $result->exceptionMessage);
    }

    public function test_it_supports_form_payloads_limits_bodies_and_redacts_headers(): void
    {
        $this->app['config']->set('raceproof.capture.response_body_bytes', 4);
        $this->app['config']->set('raceproof.capture.headers', ['X-Public', 'X-Secret', 'X-Missing']);
        $this->app['config']->set('raceproof.capture.redact_headers', ['x-secret']);
        Route::post('/raceproof/form', fn () => response(
            request()->string('value')->toString().'response',
            200,
            ['X-Public' => 'visible', 'X-Secret' => 'sensitive'],
        ));
        $plan = $this->plan(new RequestSpec(
            method: 'post',
            uri: '/raceproof/form',
            payload: ['value' => 'form-'],
            headers: ['X-Input' => 'present'],
            cookies: ['locale' => 'en'],
            json: false,
        ));

        $result = $this->app->make(RequestExecutor::class)->execute(
            $plan,
            new ParticipantContext($plan->runId, 'p2'),
        );

        self::assertSame('form', $result->body);
        self::assertSame('visible', $result->headers['x-public']);
        self::assertSame('[REDACTED]', $result->headers['x-secret']);
        self::assertArrayNotHasKey('x-missing', $result->headers);
    }

    private function plan(RequestSpec $request): RacePlan
    {
        return new RacePlan(str_repeat('1', 32), 2, $request);
    }
}
