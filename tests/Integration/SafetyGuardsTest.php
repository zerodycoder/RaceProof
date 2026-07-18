<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Exceptions\EnvironmentRejected;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;

final class SafetyGuardsTest extends TestCase
{
    public function test_it_rejects_an_open_parent_database_transaction(): void
    {
        $connection = $this->app['db']->connection();
        $connection->beginTransaction();

        try {
            $this->expectException(EnvironmentRejected::class);
            $this->expectExceptionMessage('open transaction');

            $this->app->make(DatabaseSafety::class)->validate();
        } finally {
            $connection->rollBack();
        }
    }

    public function test_it_rejects_a_database_outside_the_allowlist(): void
    {
        $this->app['config']->set('raceproof.database.allowed_names', ['dedicated_raceproof_test']);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('is not in RACEPROOF_ALLOWED_DATABASES');

        $this->app->make(DatabaseSafety::class)->validate();
    }

    public function test_it_refuses_to_run_when_disabled(): void
    {
        $this->app['config']->set('raceproof.enabled', false);

        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('RaceProof is disabled');

        $this->app->make(EnvironmentGuard::class)->ensureEnabled();
    }
}
