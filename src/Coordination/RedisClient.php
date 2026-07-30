<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Coordination;

/**
 * @internal Redis client differences are normalized behind this boundary.
 */
interface RedisClient
{
    /** @param list<int|string> $arguments */
    public function command(string $command, array $arguments = []): mixed;

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $arguments
     */
    public function evaluate(string $script, array $keys, array $arguments = []): mixed;
}
