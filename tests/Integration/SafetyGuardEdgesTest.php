<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Exceptions\EnvironmentRejected;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;

final class SafetyGuardEdgesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('RACEPROOF_ALLOW_NON_TESTING');

        parent::tearDown();
    }

    public function test_environment_guard_always_rejects_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('refuses to run in the production');

        $this->app->make(EnvironmentGuard::class)->ensureEnabled();
    }

    public function test_environment_guard_rejects_non_testing_without_explicit_opt_in(): void
    {
        $this->app['env'] = 'local';
        putenv('RACEPROOF_ALLOW_NON_TESTING');

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('only runs in testing');

        $this->app->make(EnvironmentGuard::class)->ensureEnabled();
    }

    public function test_environment_guard_allows_an_explicit_isolated_local_opt_in(): void
    {
        $this->app['env'] = 'local';
        putenv('RACEPROOF_ALLOW_NON_TESTING=1');

        $this->app->make(EnvironmentGuard::class)->ensureEnabled();

        self::assertTrue(true);
    }

    public function test_database_guard_rejects_in_memory_sqlite_when_enabled(): void
    {
        $this->app['config']->set('raceproof.database.reject_in_memory_sqlite', true);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('SQLite in-memory databases');

        $this->app->make(DatabaseSafety::class)->validate();
    }
}
