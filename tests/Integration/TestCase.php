<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase as Orchestra;
use RaceProof\Laravel\RaceProofServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RaceProofServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('raceproof.enabled', true);
        $app['config']->set('raceproof.database.reject_in_memory_sqlite', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
