<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final class JsonValue
{
    /** @param array<mixed> $value */
    public static function assertObject(array $value, string $path, string $label = 'Field'): void
    {
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidRacePlan("{$label} [{$path}] must use string object keys.");
            }

            self::assert($item, $path.'.'.$key, $label);
        }
    }

    public static function assert(mixed $value, string $path, string $label = 'Field'): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value) && is_finite($value)) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidRacePlan("{$label} [{$path}] is not JSON-safe.");
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::assert($item, $path.'.'.$index, $label);
            }

            return;
        }

        self::assertObject($value, $path, $label);
    }
}
