<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use LogicException;
use RaceProof\Laravel\Contracts\WorkerProcess;
use Symfony\Component\Process\Process;

final class SymfonyWorkerProcess implements WorkerProcess
{
    private bool $started = false;

    private ?int $exitCode = null;

    public function __construct(private readonly Process $process) {}

    public function start(): void
    {
        $this->process->start();
        $this->started = true;
    }

    public function isRunning(): bool
    {
        return $this->started && $this->process->isRunning();
    }

    public function stop(float $timeoutSeconds): void
    {
        if ($this->isRunning()) {
            $this->process->stop($timeoutSeconds);
        }
    }

    public function wait(): int
    {
        if (! $this->started) {
            throw new LogicException('Cannot wait for a worker process that was not started.');
        }

        return $this->exitCode ??= $this->process->wait();
    }

    public function exitCode(): ?int
    {
        if (! $this->started) {
            return null;
        }

        return $this->exitCode ?? $this->process->getExitCode();
    }

    public function output(): string
    {
        return $this->process->getOutput();
    }

    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }
}
