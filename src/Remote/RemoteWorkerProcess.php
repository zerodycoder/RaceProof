<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use LogicException;
use RaceProof\Laravel\Contracts\WorkerControlClock;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Exceptions\RemoteControlUnavailable;

/**
 * @internal
 */
final class RemoteWorkerProcess implements WorkerProcess
{
    private bool $started = false;

    private ?int $exitCode = null;

    private string $output = '';

    private string $errorOutput = '';

    private ?int $waitDeadlineMs = null;

    public function __construct(
        private readonly string $runId,
        private readonly string $participantId,
        private readonly string $agentId,
        private readonly WorkerControlPlane $control,
        private readonly RemoteControlMessageCodec $codec,
        private readonly RemoteWorkerConfiguration $config,
        private readonly WorkerControlClock $clock,
    ) {}

    public function start(): void
    {
        if ($this->started) {
            throw new LogicException('Cannot start a remote worker process twice.');
        }

        $control = $this->codec->issue(
            RemoteControlMessage::ACTION_START,
            $this->agentId,
            $this->runId,
            $this->participantId,
        );
        try {
            $this->control->dispatch($control['message'], $control['envelope']);
        } catch (RemoteControlUnavailable $exception) {
            $state = $this->control->state($this->runId, $this->participantId);

            if ($state === null || $state->agentId !== $this->agentId || $state->terminal()) {
                throw $exception;
            }
        }

        $this->started = true;
    }

    public function isRunning(): bool
    {
        if (! $this->started || $this->exitCode !== null) {
            return false;
        }

        $this->refresh();

        return $this->exitCode === null;
    }

    public function stop(float $timeoutSeconds): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $control = $this->codec->issue(
            RemoteControlMessage::ACTION_STOP,
            $this->agentId,
            $this->runId,
            $this->participantId,
        );
        $this->control->dispatch($control['message'], $control['envelope']);
        $requestedMs = max(1, (int) ceil($timeoutSeconds * 1_000));
        $this->waitDeadlineMs = $this->clock->monotonicMilliseconds()
            + min($requestedMs, $this->config->shutdownTimeoutMs);
    }

    public function wait(): int
    {
        if (! $this->started) {
            throw new LogicException('Cannot wait for a remote worker process that was not started.');
        }

        if ($this->exitCode !== null) {
            return $this->exitCode;
        }

        $deadline = $this->waitDeadlineMs
            ?? ($this->clock->monotonicMilliseconds() + $this->config->shutdownTimeoutMs);

        while (true) {
            $this->refresh();

            if ($this->exitCode !== null) {
                break;
            }

            if ($this->clock->monotonicMilliseconds() >= $deadline) {
                $this->fail('Remote worker did not reach a terminal state before the shutdown timeout.');
                break;
            }

            $this->clock->sleepMilliseconds($this->config->pollIntervalMs);
        }

        return $this->exitCode ?? 1;
    }

    public function exitCode(): ?int
    {
        if (! $this->started || $this->exitCode !== null) {
            return $this->exitCode;
        }

        $this->refresh();

        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    private function refresh(): void
    {
        $state = $this->control->state($this->runId, $this->participantId);

        if ($state === null) {
            $this->fail('Remote worker control state disappeared.');

            return;
        }

        if ($state->agentId !== $this->agentId) {
            throw new RaceProofException('RaceProof remote worker control state was routed to an unexpected agent.');
        }

        if (
            in_array($state->status, ['pending', 'claimed'], true)
            && $this->clock->nowMilliseconds() >= $state->expiresAtMs
        ) {
            $this->fail('Remote worker was not launched before its control message expired.');

            return;
        }

        if (! $state->terminal()) {
            return;
        }

        $this->exitCode = $state->exitCode;
        $this->output = $state->output;
        $this->errorOutput = $state->errorOutput;
    }

    private function fail(string $message): void
    {
        $this->exitCode = 1;
        $this->errorOutput = $message;
    }
}
