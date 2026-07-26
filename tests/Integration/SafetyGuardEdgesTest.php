<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
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

    public function test_environment_guard_is_disabled_by_default_when_config_is_absent(): void
    {
        $guard = new EnvironmentGuard($this->app, new Repository);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('RaceProof is disabled');

        $guard->ensureEnabled();
    }

    public function test_database_safety_defaults_reject_open_transactions_and_memory_sqlite(): void
    {
        $safety = new DatabaseSafety($this->app['db'], new Repository);
        $connection = $this->app['db']->connection();
        $connection->beginTransaction();

        try {
            $this->expectException(EnvironmentRejected::class);
            $this->expectExceptionMessage('open transaction');

            $safety->validate();
        } finally {
            $connection->rollBack();
        }
    }

    public function test_database_safety_default_rejects_memory_sqlite_without_a_transaction(): void
    {
        $safety = new DatabaseSafety($this->app['db'], new Repository);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('SQLite in-memory databases');

        $safety->validate();
    }

    public function test_database_safety_requires_an_explicit_allowlist_when_enabled(): void
    {
        $config = new Repository([
            'raceproof' => [
                'database' => [
                    'reject_in_memory_sqlite' => false,
                    'require_allowlist' => true,
                    'allowed_names' => [],
                ],
            ],
        ]);
        $safety = new DatabaseSafety($this->app['db'], $config);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('is not in RACEPROOF_ALLOWED_DATABASES');

        $safety->validate();
    }

    public function test_database_safety_allows_the_database_without_requiring_a_list(): void
    {
        $this->expectNotToPerformAssertions();
        $config = new Repository([
            'raceproof' => [
                'database' => [
                    'reject_in_memory_sqlite' => false,
                ],
            ],
        ]);

        (new DatabaseSafety($this->app['db'], $config))->validate();
    }

    public function test_database_safety_rejects_an_empty_sqlite_database_name(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactionLevel')->willReturn(0);
        $connection->method('getDatabaseName')->willReturn('');
        $connection->method('getDriverName')->willReturn('sqlite');
        $database = $this->createMock(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('SQLite in-memory databases');

        (new DatabaseSafety($database, new Repository))->validate();
    }
}
