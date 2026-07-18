<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;

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

            self::validateJsonValue($value, 'bootstrap.'.$key);
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

    private static function validateJsonValue(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value) && is_finite($value)) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidRacePlan("Participant bootstrap field [{$path}] is not JSON-safe.");
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::validateJsonValue($item, $path.'.'.$index);
            }

            return;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidRacePlan("Participant bootstrap field [{$path}] must use string object keys.");
            }

            self::validateJsonValue($item, $path.'.'.$key);
        }
    }
}
