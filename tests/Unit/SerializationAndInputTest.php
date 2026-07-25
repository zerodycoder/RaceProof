<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;

final class SerializationAndInputTest extends TestCase
{
    public function test_plan_and_nested_specs_round_trip_through_the_json_boundary(): void
    {
        $plan = RacePlan::fromArray([
            'run_id' => str_repeat('d', 32),
            'participants' => 4,
            'request' => [
                'method' => 'post',
                'uri' => '/checkout',
                'payload' => ['product_id' => 10],
                'headers' => ['X-Tenant' => 'acme'],
                'cookies' => ['locale' => 'en'],
                'json' => false,
            ],
            'auth' => [
                'model' => 'App\\Models\\User',
                'key' => 'user-1',
                'guard' => 'api',
            ],
            'checkpoints' => ['after-read'],
            'spawn_timeout_ms' => 500,
            'run_timeout_ms' => 750,
            'poll_interval_ms' => 2,
        ]);

        self::assertSame('POST', $plan->request->method);
        self::assertFalse($plan->request->json);
        self::assertSame('user-1', $plan->auth?->key);
        self::assertSame('api', $plan->auth?->guard);
        $serialized = json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($serialized);
        $restored = RacePlan::fromArray($serialized);

        self::assertSame($serialized, json_decode(json_encode($restored, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(
            ['model' => 'App\\Models\\User', 'key' => 5, 'guard' => 'web'],
            AuthSpec::fromArray(['model' => 'App\\Models\\User', 'key' => 5])->jsonSerialize(),
        );
        self::assertSame([], $restored->participantSpecs);
    }

    /** @return iterable<string, array{Closure(): void}> */
    public static function invalidPlanValues(): iterable
    {
        $request = new RequestSpec('POST', '/checkout');

        yield 'run id' => [static fn () => new RacePlan('not-hex', 2, $request)];
        yield 'participant lower bound' => [static fn () => new RacePlan(str_repeat('a', 32), 1, $request)];
        yield 'participant upper bound' => [static fn () => new RacePlan(str_repeat('a', 32), 101, $request)];
        yield 'timeout' => [static fn () => new RacePlan(str_repeat('a', 32), 2, $request, spawnTimeoutMs: 0)];
        yield 'checkpoint' => [static fn () => new RacePlan(str_repeat('a', 32), 2, $request, checkpoints: ['bad checkpoint'])];
        yield 'request URI' => [static fn () => new RequestSpec('POST', 'checkout')];
    }

    #[DataProvider('invalidPlanValues')]
    public function test_value_objects_reject_invalid_domain_values(Closure $operation): void
    {
        $this->expectException(InvalidRacePlan::class);

        $operation();
    }

    /** @return iterable<string, array{Closure(): void}> */
    public static function invalidInputValues(): iterable
    {
        yield 'optional string' => [static fn () => Input::optionalString(['value' => 1], 'value')];
        yield 'optional integer' => [static fn () => Input::optionalInteger(['value' => '1'], 'value')];
        yield 'boolean' => [static fn () => Input::boolean(['value' => 1], 'value', false)];
        yield 'identifier' => [static fn () => Input::key(['value' => false], 'value')];
        yield 'map type' => [static fn () => Input::mapValue('not-an-object', 'value')];
        yield 'map numeric key' => [static fn () => Input::mapValue(['first'], 'value')];
        yield 'string list shape' => [static fn () => Input::stringList(['value' => ['name' => 'checkpoint']], 'value')];
        yield 'string list member' => [static fn () => Input::stringList(['value' => [1]], 'value')];
    }

    #[DataProvider('invalidInputValues')]
    public function test_input_boundary_rejects_ambiguous_types(Closure $operation): void
    {
        $this->expectException(InvalidRacePlan::class);

        $operation();
    }
}
