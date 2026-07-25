<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Closure;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\ParticipantBuilder;
use stdClass;

final class ParticipantSpecTest extends TestCase
{
    public function test_it_round_trips_and_applies_request_overrides_over_global_defaults(): void
    {
        $spec = new ParticipantSpec(
            payload: ['participant' => 1],
            headers: ['X-Shared' => 'participant', 'X-Participant' => 'p1'],
            cookies: ['participant' => 'p1'],
            auth: new AuthSpec(FixtureParticipantUser::class, 1),
            bootstrap: new BootstrapSpec(ParticipantSpecBootstrap::class, ['tenant' => 'p1']),
        );
        $decoded = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = ParticipantSpec::fromArray($decoded);
        $request = $restored->request(new RequestSpec(
            'POST',
            '/checkout',
            ['global' => true],
            ['X-Shared' => 'global', 'X-Global' => 'yes'],
            ['locale' => 'en'],
        ));

        self::assertEquals($spec, $restored);
        self::assertSame(['participant' => 1], $request->payload);
        self::assertSame([
            'X-Shared' => 'participant',
            'X-Global' => 'yes',
            'X-Participant' => 'p1',
        ], $request->headers);
        self::assertSame(['locale' => 'en', 'participant' => 'p1'], $request->cookies);
    }

    public function test_empty_participant_payload_explicitly_replaces_a_global_payload(): void
    {
        $request = (new ParticipantSpec(payload: []))->request(
            new RequestSpec('POST', '/checkout', ['global' => true]),
        );

        self::assertSame([], $request->payload);
    }

    public function test_the_fluent_participant_builder_accumulates_safe_overrides(): void
    {
        $user = new FixtureParticipantUser;
        $user->setAttribute('id', 7);
        $user->exists = true;

        $spec = (new ParticipantBuilder)
            ->withPayload(['participant' => 7])
            ->withHeaders(['X-Participant' => 'p7'])
            ->withCookies(['participant' => 'p7'])
            ->withToken('token-7')
            ->actingAs($user, 'api')
            ->withBootstrap(ParticipantSpecBootstrap::class, ['tenant' => 'seven'])
            ->spec();

        self::assertSame(['participant' => 7], $spec->payload);
        self::assertSame('Bearer token-7', $spec->headers['Authorization']);
        self::assertSame('p7', $spec->cookies['participant']);
        self::assertSame(7, $spec->auth?->key);
        self::assertSame('api', $spec->auth?->guard);
        self::assertSame(['tenant' => 'seven'], $spec->bootstrap?->configuration);
    }

    public function test_plan_resolves_participant_defaults_and_overrides(): void
    {
        $globalAuth = new AuthSpec(FixtureParticipantUser::class, 1);
        $participantAuth = new AuthSpec(FixtureParticipantUser::class, 2, 'api');
        $globalBootstrap = new BootstrapSpec(ParticipantSpecBootstrap::class, ['scope' => 'global']);
        $participantBootstrap = new BootstrapSpec(ParticipantSpecBootstrap::class, ['scope' => 'p2']);
        $plan = new RacePlan(
            str_repeat('a', 32),
            2,
            new RequestSpec('POST', '/checkout', ['scope' => 'global']),
            auth: $globalAuth,
            bootstrap: $globalBootstrap,
            participantSpecs: [
                'p2' => new ParticipantSpec(
                    payload: ['scope' => 'p2'],
                    auth: $participantAuth,
                    bootstrap: $participantBootstrap,
                ),
            ],
        );

        self::assertSame(['scope' => 'global'], $plan->requestFor('p1')->payload);
        self::assertSame(['scope' => 'p2'], $plan->requestFor('p2')->payload);
        self::assertSame($globalAuth, $plan->authFor('p1'));
        self::assertSame($participantAuth, $plan->authFor('p2'));
        self::assertSame($globalBootstrap, $plan->bootstrapFor('p1'));
        self::assertSame($participantBootstrap, $plan->bootstrapFor('p2'));

        $serialized = json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($plan, RacePlan::fromArray($serialized));
    }

    /** @return iterable<string, array{Closure(): void}> */
    public static function invalidOverrides(): iterable
    {
        yield 'object payload' => [
            static fn () => new ParticipantSpec(payload: ['unsafe' => new stdClass]),
        ];
        yield 'non-finite payload' => [
            static fn () => new ParticipantSpec(payload: ['unsafe' => INF]),
        ];
        yield 'numeric object keys' => [
            static fn () => new ParticipantSpec(payload: [2 => 'sparse']),
        ];
        yield 'header injection' => [
            static fn () => new ParticipantSpec(headers: ['X-Test' => "safe\r\nX-Evil: injected"]),
        ];
        yield 'invalid cookie name' => [
            static fn () => new ParticipantSpec(cookies: ['not valid' => 'cookie']),
        ];
        yield 'unknown participant' => [
            static fn () => new RacePlan(
                str_repeat('a', 32),
                2,
                new RequestSpec('POST', '/checkout'),
                participantSpecs: ['p3' => new ParticipantSpec],
            ),
        ];
        yield 'empty bearer token' => [
            static fn () => (new ParticipantBuilder)->withToken('  '),
        ];
        yield 'invalid token scheme' => [
            static fn () => (new ParticipantBuilder)->withToken('token', 'not a scheme'),
        ];
    }

    #[DataProvider('invalidOverrides')]
    public function test_it_rejects_unsafe_or_unknown_participant_overrides(Closure $operation): void
    {
        $this->expectException(InvalidRacePlan::class);

        $operation();
    }
}

final class FixtureParticipantUser extends Authenticatable
{
    protected $guarded = [];
}

final class ParticipantSpecBootstrap implements ParticipantBootstrap
{
    public function bootstrap(ParticipantContext $context, array $configuration): void {}
}
