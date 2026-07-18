<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use RaceProof\Laravel\Exceptions\EnvironmentRejected;

final readonly class EnvironmentGuard
{
    public function __construct(private Application $app, private Config $config) {}

    public function ensureEnabled(): void
    {
        if ($this->app->environment('production')) {
            throw new EnvironmentRejected('RaceProof refuses to run in the production environment.');
        }

        if (! ConfigValue::boolean($this->config, 'raceproof.enabled', false)) {
            throw new EnvironmentRejected(
                'RaceProof is disabled. Run in APP_ENV=testing or explicitly set RACEPROOF_ENABLED=true.',
            );
        }

        if (! $this->app->environment('testing') && getenv('RACEPROOF_ALLOW_NON_TESTING') !== '1') {
            throw new EnvironmentRejected(
                'RaceProof only runs in testing by default. Set RACEPROOF_ALLOW_NON_TESTING=1 for an isolated local environment.',
            );
        }
    }
}
