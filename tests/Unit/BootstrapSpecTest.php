<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use stdClass;

final class BootstrapSpecTest extends TestCase
{
    public function test_it_round_trips_nested_json_safe_configuration(): void
    {
        $spec = new BootstrapSpec(FixtureBootstrap::class, [
            'tenant' => 'acme',
            'flags' => ['mail' => false, 'retries' => 2],
            'roles' => ['buyer', 'reviewer'],
        ]);

        $decoded = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($spec, BootstrapSpec::fromArray($decoded));
    }

    public function test_it_rejects_objects_and_executable_closure_configuration(): void
    {
        foreach ([new stdClass, static fn (): bool => true] as $unsafe) {
            try {
                new BootstrapSpec(FixtureBootstrap::class, ['unsafe' => $unsafe]);
                self::fail('Expected unsafe bootstrap configuration to be rejected.');
            } catch (InvalidRacePlan $exception) {
                self::assertStringContainsString('not JSON-safe', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_non_finite_numbers(): void
    {
        $this->expectException(InvalidRacePlan::class);

        new BootstrapSpec(FixtureBootstrap::class, ['value' => INF]);
    }

    public function test_it_rejects_a_class_without_the_bootstrap_contract(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('must implement');

        new BootstrapSpec(stdClass::class);
    }

    public function test_it_rejects_non_string_configuration_keys(): void
    {
        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('string keys');

        new BootstrapSpec(FixtureBootstrap::class, [
            2 => 'not-a-list',
            4 => 'not-a-map',
        ]);
    }
}

final class FixtureBootstrap implements ParticipantBootstrap
{
    public function bootstrap(ParticipantContext $context, array $configuration): void {}
}
