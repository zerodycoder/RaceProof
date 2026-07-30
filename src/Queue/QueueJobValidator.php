<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Queue;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use ReflectionObject;

/**
 * @internal Queue jobs use RaceProof-owned retry and isolation policy.
 */
final class QueueJobValidator
{
    public function validate(mixed $job): object
    {
        if (! is_object($job) || ! $job instanceof ShouldQueue) {
            throw new InvalidRacePlan('Each queue participant must be an object implementing ShouldQueue.');
        }

        if (
            $job instanceof ShouldBeUnique
            || $job instanceof ShouldBeUniqueUntilProcessing
            || $job instanceof ShouldBeEncrypted
            || $job instanceof ShouldQueueAfterCommit
        ) {
            throw new InvalidRacePlan('Unique, encrypted, and after-commit jobs are not supported in queue races.');
        }

        foreach (['connection', 'queue', 'delay', 'afterCommit'] as $property) {
            if ($this->propertyValue($job, $property) !== null) {
                throw new InvalidRacePlan(
                    "Queue jobs must not define their own {$property}; configure it through RaceProof.",
                );
            }
        }

        foreach (['tries', 'maxExceptions', 'backoff', 'timeout', 'retryUntil'] as $property) {
            if ($this->propertyValue($job, $property) !== null) {
                throw new InvalidRacePlan(
                    "Queue jobs must not define their own {$property} policy in a queue race.",
                );
            }
        }

        $failOnTimeout = $this->propertyValue($job, 'failOnTimeout');

        if ($failOnTimeout !== null && $failOnTimeout !== false) {
            throw new InvalidRacePlan(
                'Queue jobs must not define their own failOnTimeout policy in a queue race.',
            );
        }

        foreach (['shouldBeEncrypted', 'deleteWhenMissingModels'] as $property) {
            if ($this->propertyValue($job, $property) === true) {
                throw new InvalidRacePlan(
                    "Queue jobs must not enable their own {$property} policy in a queue race.",
                );
            }
        }

        foreach (['chained', 'chainCatchCallbacks'] as $property) {
            $value = $this->propertyValue($job, $property);

            if (is_array($value) && $value !== []) {
                throw new InvalidRacePlan('Queued chains are not supported in queue races.');
            }
        }

        foreach (['chainConnection', 'chainQueue', 'batchId'] as $property) {
            if ($this->propertyValue($job, $property) !== null) {
                throw new InvalidRacePlan('Queued chains and batches are not supported in queue races.');
            }
        }

        foreach (['tries', 'retryUntil', 'backoff'] as $method) {
            if (method_exists($job, $method)) {
                throw new InvalidRacePlan(
                    "Queue jobs must not define their own {$method} policy in a queue race.",
                );
            }
        }

        $reflection = new ReflectionObject($job);

        foreach ([
            'Illuminate\\Queue\\Attributes\\Backoff',
            'Illuminate\\Queue\\Attributes\\Connection',
            'Illuminate\\Queue\\Attributes\\DebounceFor',
            'Illuminate\\Queue\\Attributes\\Delay',
            'Illuminate\\Queue\\Attributes\\DeleteWhenMissingModels',
            'Illuminate\\Queue\\Attributes\\FailOnTimeout',
            'Illuminate\\Queue\\Attributes\\MaxExceptions',
            'Illuminate\\Queue\\Attributes\\Queue',
            'Illuminate\\Queue\\Attributes\\Timeout',
            'Illuminate\\Queue\\Attributes\\Tries',
            'Illuminate\\Queue\\Attributes\\UniqueFor',
        ] as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                throw new InvalidRacePlan('Queue jobs must not define queue policy attributes.');
            }
        }

        return $job;
    }

    private function propertyValue(object $job, string $name): mixed
    {
        $reflection = new ReflectionObject($job);

        if (! $reflection->hasProperty($name)) {
            return null;
        }

        $property = $reflection->getProperty($name);

        if (! $property->isInitialized($job)) {
            return null;
        }

        return $property->getValue($job);
    }
}
