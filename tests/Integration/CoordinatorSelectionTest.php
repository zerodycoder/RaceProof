<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RuntimeException;

final class CoordinatorSelectionTest extends TestCase
{
    public function test_parent_resolves_the_configured_file_backend(): void
    {
        $path = dirname(__DIR__, 2).'/build/coordinator-selection/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.coordinator.driver', 'file');
        $this->app['config']->set('raceproof.coordinator.path', $path);
        $this->forgetCoordinator();

        $store = $this->app->make(CoordinatorStore::class);

        self::assertInstanceOf(FileCoordinatorStore::class, $store);
        self::assertSame($path.'/'.str_repeat('a', 32), $store->artifactReference(str_repeat('a', 32)));
    }

    public function test_unsupported_driver_fails_before_worker_factory_resolution(): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';
        $factoryResolved = false;
        $this->app['config']->set('raceproof.coordinator.driver', $secret);
        $this->forgetCoordinator();
        $this->app->bind(WorkerProcessFactory::class, function () use (&$factoryResolved): never {
            $factoryResolved = true;

            throw new RuntimeException('Worker factory must not resolve.');
        });

        try {
            $this->app->make(RaceOrchestrator::class);
            self::fail('Expected coordinator selection to fail before orchestration.');
        } catch (RaceProofException $exception) {
            self::assertSame(
                'RaceProof coordinator driver configuration is unsupported.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }

        self::assertFalse($factoryResolved);
    }

    private function forgetCoordinator(): void
    {
        $this->app->forgetInstance(FileCoordinatorStore::class);
        $this->app->forgetInstance(CoordinatorResolver::class);
        $this->app->forgetInstance(CoordinatorStore::class);
    }
}
