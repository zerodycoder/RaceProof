<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Support;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Runtime\Checkpoint;
use RuntimeException;

final readonly class FixtureParticipantBootstrap implements ParticipantBootstrap
{
    public function __construct(
        private Config $config,
        private AuthFactory $auth,
    ) {}

    public function bootstrap(ParticipantContext $context, array $configuration): void
    {
        if (Checkpoint::active()) {
            throw new RuntimeException('Runtime checkpoint capability activated before bootstrap completed.');
        }

        $environmentPrefix = $this->string($configuration, 'environment_prefix');
        $configPrefix = $this->string($configuration, 'config_prefix');
        $userPrefix = $this->string($configuration, 'user_prefix');
        $environment = $environmentPrefix.$context->participantId;
        putenv('RACEPROOF_BOOTSTRAP_ENV='.$environment);
        $this->config->set('raceproof.fixture.bootstrap', $configPrefix.$context->participantId);

        $guard = $this->auth->guard();

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Fixture authentication guard must be stateful.');
        }

        $guard->setUser(new GenericUser([
            'id' => $userPrefix.$context->participantId,
            'name' => 'RaceProof '.$context->participantId,
        ]));
    }

    /** @param array<string, mixed> $configuration */
    private function string(array $configuration, string $key): string
    {
        $value = $configuration[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Fixture bootstrap field [{$key}] must be a string.");
        }

        return $value;
    }
}
