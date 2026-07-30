<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Facades\RacePoint as RacePointFacade;
use RaceProof\Laravel\RaceBuilder;

final class RaceBuilderTest extends TestCase
{
    public function test_run_requires_a_request(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('Define a request');

        $this->app->make(RaceBuilder::class)->run();
    }

    public function test_http_and_queue_modes_are_mutually_exclusive(): void
    {
        foreach ([
            fn () => $this->app->make(RaceBuilder::class)
                ->postJson('/checkout')
                ->queue(static fn (string $participant): BuilderQueueJob => new BuilderQueueJob($participant)),
            fn () => $this->app->make(RaceBuilder::class)
                ->queue(static fn (string $participant): BuilderQueueJob => new BuilderQueueJob($participant))
                ->withHeaders(['X-Test' => 'value']),
            fn () => $this->app->make(RaceBuilder::class)
                ->queue(static fn (string $participant): BuilderQueueJob => new BuilderQueueJob($participant))
                ->forParticipant('p1', static fn ($participant) => $participant->withPayload(['unsafe' => true])),
        ] as $invalidBuilder) {
            try {
                $invalidBuilder();
                self::fail('Expected mixed HTTP and queue configuration to be rejected.');
            } catch (InvalidRacePlan) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_queue_attempt_policy_is_explicit_and_bounded(): void
    {
        try {
            $this->app->make(RaceBuilder::class)->queueAttempts(2);
            self::fail('Expected attempts without queue jobs to be rejected.');
        } catch (InvalidRacePlan) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('between 1 and 5');

        $this->app->make(RaceBuilder::class)
            ->queue(static fn (string $participant): BuilderQueueJob => new BuilderQueueJob($participant))
            ->queueAttempts(6)
            ->run();
    }

    public function test_environment_refusal_happens_before_the_queue_job_factory_runs(): void
    {
        $called = false;
        $this->app['config']->set('app.env', 'production');

        try {
            $this->app->make(RaceBuilder::class)
                ->queue(function (string $participant) use (&$called): BuilderQueueJob {
                    $called = true;

                    return new BuilderQueueJob($participant);
                })
                ->run();
            self::fail('Expected production queue execution to be rejected.');
        } catch (\Throwable) {
            self::assertFalse($called);
        }
    }

    public function test_unsupported_queue_connection_is_rejected_before_the_job_factory_runs(): void
    {
        $called = false;

        try {
            $this->app->make(RaceBuilder::class)
                ->queue(function (string $participant) use (&$called): BuilderQueueJob {
                    $called = true;

                    return new BuilderQueueJob($participant);
                }, 'default')
                ->run();
            self::fail('Expected the unsupported queue connection to be rejected.');
        } catch (InvalidRacePlan) {
            self::assertFalse($called);
        }
    }

    public function test_queue_factory_requires_distinct_jobs_and_redacts_factory_failures(): void
    {
        $this->app['config']->set('queue.connections.raceproof_testing', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 30,
        ]);
        $shared = new BuilderQueueJob('shared');

        try {
            $this->app->make(RaceBuilder::class)
                ->participants(2)
                ->queue(static fn (): BuilderQueueJob => $shared, 'raceproof_testing')
                ->run();
            self::fail('Expected a shared queue job object to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertStringContainsString('distinct job object', $exception->getMessage());
        }

        try {
            $this->app->make(RaceBuilder::class)
                ->participants(2)
                ->queue(
                    static function (): never {
                        throw new \RuntimeException('token=queue-factory-secret');
                    },
                    'raceproof_testing',
                )
                ->run();
            self::fail('Expected the failing queue job factory to be rejected.');
        } catch (InvalidRacePlan $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString('token=[REDACTED]', $exception->getMessage());
            self::assertStringNotContainsString('queue-factory-secret', $exception->getMessage());
        }

        try {
            $this->app->make(RaceBuilder::class)
                ->participants(2)
                ->queue(
                    static function (): never {
                        throw new InvalidRacePlan('token=invalid-plan-secret');
                    },
                    'raceproof_testing',
                )
                ->run();
            self::fail('Expected every queue factory exception type to be redacted.');
        } catch (InvalidRacePlan $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString('token=[REDACTED]', $exception->getMessage());
            self::assertStringNotContainsString('invalid-plan-secret', $exception->getMessage());
        }
    }

    public function test_acting_as_requires_a_persisted_model(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('persisted Eloquent model');

        $this->app->make(RaceBuilder::class)->actingAs(new BuilderUser);
    }

    public function test_acting_as_rejects_an_unpersisted_model_even_when_it_has_a_key(): void
    {
        $user = new BuilderUser;
        $user->setAttribute('id', 'assigned-but-not-persisted');

        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('persisted Eloquent model');

        $this->app->make(RaceBuilder::class)->actingAs($user);
    }

    public function test_acting_as_rejects_a_persisted_model_without_the_authenticatable_contract(): void
    {
        $user = new NonAuthenticatableBuilderModel;
        $user->setAttribute('id', 'model-1');
        $user->exists = true;

        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('implements Authenticatable');

        $this->app->make(RaceBuilder::class)->actingAs($user);
    }

    public function test_acting_as_accepts_a_model_with_a_scalar_key(): void
    {
        $user = new BuilderUser;
        $user->setAttribute('id', 'user-1');
        $user->exists = true;

        $builder = $this->app->make(RaceBuilder::class)->actingAs($user, 'api');

        self::assertInstanceOf(RaceBuilder::class, $builder);
    }

    public function test_the_facade_is_a_safe_noop_outside_a_worker(): void
    {
        RacePointFacade::sync('outside-worker');

        self::assertTrue(true);
    }

    public function test_it_rejects_a_participant_override_outside_the_race_before_orchestration(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('outside this 2-participant race');

        $this->app->make(RaceBuilder::class)
            ->participants(2)
            ->postJson('/checkout')
            ->forParticipant('p3', static fn ($participant) => $participant->withPayload(['item' => 3]))
            ->run();
    }

    public function test_it_rejects_malformed_participant_ids_when_the_override_is_defined(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('expected p1 through p100');

        $this->app->make(RaceBuilder::class)
            ->forParticipant('participant-1', static fn (): null => null);
    }
}

final class BuilderUser extends Authenticatable
{
    protected $guarded = [];
}

final class NonAuthenticatableBuilderModel extends Model
{
    protected $guarded = [];
}

final class BuilderQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $participantId) {}
}
