<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final class Input
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function integer(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? $default;

        if (! is_int($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be an integer or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function boolean(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        if (! is_bool($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be a boolean.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function key(array $data, string $key): int|string
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be an integer or string identifier.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key, array $default = []): array
    {
        return self::mapValue($data[$key] ?? $default, $key);
    }

    /** @return array<string, mixed> */
    public static function mapValue(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new InvalidRacePlan("Field [{$context}] must be an object.");
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidRacePlan("Field [{$context}] must use string keys.");
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $values = self::map($data, $key);
        $result = [];

        foreach ($values as $name => $value) {
            if (! is_string($value)) {
                throw new InvalidRacePlan("Field [{$key}.{$name}] must be a string.");
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidRacePlan("Field [{$key}] must be a list.");
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidRacePlan("Field [{$key}] must contain only strings.");
            }
        }

        return $value;
    }
}
