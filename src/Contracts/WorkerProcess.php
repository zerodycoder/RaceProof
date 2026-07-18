<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

interface WorkerProcess
{
    public function start(): void;

    public function isRunning(): bool;

    public function stop(float $timeoutSeconds): void;

    public function wait(): int;

    public function exitCode(): ?int;

    public function output(): string;

    public function errorOutput(): string;
}
