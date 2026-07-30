<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

/**
 * @internal Worker transports are selected through package configuration.
 */
interface WorkerTransport extends WorkerProcessFactory
{
    public function driver(): string;

    public function healthCheck(): void;
}
