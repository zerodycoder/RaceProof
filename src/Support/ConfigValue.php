<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Exceptions\RaceProofException;

final class ConfigValue
{
    public static function string(Config $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value)) {
            throw new RaceProofException("Configuration [{$key}] must be a string.");
        }

        return $value;
    }

    public static function integer(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        if (! is_int($value)) {
            throw new RaceProofException("Configuration [{$key}] must be an integer.");
        }

        return $value;
    }

    public static function boolean(Config $config, string $key, bool $default): bool
    {
        $value = $config->get($key, $default);

        if (! is_bool($value)) {
            throw new RaceProofException("Configuration [{$key}] must be a boolean.");
        }

        return $value;
    }

    /** @return list<string> */
    public static function stringList(Config $config, string $key): array
    {
        $value = $config->get($key, []);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RaceProofException("Configuration [{$key}] must be a list of strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RaceProofException("Configuration [{$key}] must contain only strings.");
            }
        }

        return $value;
    }
}
