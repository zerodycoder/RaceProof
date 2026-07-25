<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;
use RaceProof\Laravel\Support\JsonValue;

final readonly class BootstrapSpec implements JsonSerializable
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $class,
        public array $configuration = [],
    ) {
        if (! is_a($class, ParticipantBootstrap::class, true)) {
            throw new InvalidRacePlan("Participant bootstrap [{$class}] must implement ".ParticipantBootstrap::class.'.');
        }

        foreach ($configuration as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidRacePlan('Participant bootstrap configuration must use string keys.');
            }

            JsonValue::assert($value, 'bootstrap.'.$key, 'Participant bootstrap field');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Input::string($data, 'class'),
            Input::map($data, 'configuration'),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'class' => $this->class,
            'configuration' => (object) $this->configuration,
        ];
    }
}
