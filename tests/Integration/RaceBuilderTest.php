<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
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
}

final class BuilderUser extends Model
{
    protected $guarded = [];
}
