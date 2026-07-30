<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use RaceProof\Laravel\Exceptions\EnvironmentRejected;

final readonly class DatabaseSafety
{
    public function __construct(private DatabaseManager $database, private Config $config) {}

    public function validate(): void
    {
        $this->validateConnection();
    }

    public function validateConnection(?string $name = null): void
    {
        $connection = $this->database->connection($name);

        if (
            ConfigValue::boolean($this->config, 'raceproof.database.reject_open_transactions', true)
            && $connection->transactionLevel() > 0
        ) {
            throw new EnvironmentRejected(
                'RaceProof cannot start while the test connection has an open transaction. Use DatabaseMigrations or a dedicated race-test database.',
            );
        }

        $databaseName = (string) $connection->getDatabaseName();

        if (
            ConfigValue::boolean($this->config, 'raceproof.database.reject_in_memory_sqlite', true)
            && $connection->getDriverName() === 'sqlite'
            && ($databaseName === ':memory:' || $databaseName === '')
        ) {
            throw new EnvironmentRejected(
                'SQLite in-memory databases cannot be shared by RaceProof worker processes.',
            );
        }

        $allowed = ConfigValue::stringList($this->config, 'raceproof.database.allowed_names');
        $requireAllowlist = ConfigValue::boolean($this->config, 'raceproof.database.require_allowlist', false);

        if (($requireAllowlist || $allowed !== []) && ! in_array($databaseName, $allowed, true)) {
            throw new EnvironmentRejected(
                "Database [{$databaseName}] is not in RACEPROOF_ALLOWED_DATABASES. Refusing concurrent writes.",
            );
        }
    }
}
