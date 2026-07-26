<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\ParticipantContext;

final readonly class ConsumerParticipantBootstrap implements ParticipantBootstrap
{
    public function __construct(private Config $config) {}

    /** @param array<string, mixed> $configuration */
    public function bootstrap(ParticipantContext $context, array $configuration): void
    {
        $tenant = $configuration['tenant'] ?? null;

        if (! is_string($tenant) || $tenant === '') {
            throw new \InvalidArgumentException('A non-empty tenant is required.');
        }

        $this->config->set('consumer.participant', "{$tenant}:{$context->participantId}");
    }
}
