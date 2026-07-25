<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

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
