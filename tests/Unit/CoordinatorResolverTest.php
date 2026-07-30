<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Exceptions\RaceProofException;
use stdClass;

final class CoordinatorResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_resolves_and_caches_the_safe_file_driver(): void
    {
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $store = new FileCoordinatorStore('/tmp/raceproof');
        $config->shouldReceive('get')
            ->once()
            ->with('raceproof.coordinator.driver')
            ->andReturn('file');
        $container->shouldReceive('make')
            ->once()
            ->with(FileCoordinatorStore::class)
            ->andReturn($store);
        $resolver = new CoordinatorResolver($config, $container);

        self::assertSame($store, $resolver->resolve());
        self::assertSame($store, $resolver->resolve());
    }

    public function test_it_rejects_unknown_driver_configuration_without_echoing_it(): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $config->shouldReceive('get')
            ->once()
            ->with('raceproof.coordinator.driver')
            ->andReturn($secret);
        $resolver = new CoordinatorResolver($config, $container);

        try {
            $resolver->resolve();
            self::fail('Expected an unknown coordinator driver to be rejected.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof coordinator driver configuration is unsupported.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }

    public function test_it_rejects_missing_and_malformed_driver_configuration(): void
    {
        foreach ([null, []] as $value) {
            $config = Mockery::mock(Config::class);
            $container = Mockery::mock(Container::class);
            $config->shouldReceive('get')
                ->once()
                ->with('raceproof.coordinator.driver')
                ->andReturn($value);

            try {
                (new CoordinatorResolver($config, $container))->resolve();
                self::fail('Expected malformed coordinator configuration to be rejected.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'Configuration [raceproof.coordinator.driver] must be a string.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_it_rejects_a_driver_that_resolves_the_wrong_type(): void
    {
        $config = Mockery::mock(Config::class);
        $container = Mockery::mock(Container::class);
        $config->shouldReceive('get')
            ->once()
            ->with('raceproof.coordinator.driver')
            ->andReturn('file');
        $container->shouldReceive('make')
            ->once()
            ->with(FileCoordinatorStore::class)
            ->andReturn(new stdClass);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('resolved an invalid store');

        (new CoordinatorResolver($config, $container))->resolve();
    }
}
