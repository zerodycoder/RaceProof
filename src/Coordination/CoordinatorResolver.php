<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Coordination;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\ConfigValue;

final class CoordinatorResolver
{
    private ?CoordinatorStore $resolved = null;

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    public function resolve(): CoordinatorStore
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $driver = ConfigValue::string($this->config, 'raceproof.coordinator.driver');

        $store = match ($driver) {
            'file' => $this->container->make(FileCoordinatorStore::class),
            'redis' => $this->container->make(RedisCoordinatorStore::class),
            default => throw new RaceProofException(
                'RaceProof coordinator driver configuration is unsupported.',
            ),
        };

        if (! $store instanceof CoordinatorStore) {
            throw new RaceProofException('RaceProof coordinator driver resolved an invalid store.');
        }

        return $this->resolved = $store;
    }
}
