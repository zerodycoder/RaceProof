<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Exceptions\RemoteControlUnavailable;
use RaceProof\Laravel\Remote\RemoteControlMessage;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Remote\RemoteWorkerProcess;
use RaceProof\Laravel\Remote\RemoteWorkerState;
use RaceProof\Laravel\Tests\Support\ControlledWorkerControlClock;

final class RemoteWorkerProcessTest extends TestCase
{
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_it_dispatches_start_and_exposes_a_terminal_remote_worker(): void
    {
        [$process, $control] = $this->process();

        self::assertNull($process->exitCode());
        self::assertFalse($process->isRunning());
        $process->start();
        self::assertTrue($process->isRunning());
        self::assertSame(['start'], array_column($control->dispatched, 'action'));

        $control->worker = new RemoteWorkerState(
            'failed',
            'agent-a',
            self::RUN_ID,
            'p1',
            1_700_000_015_000,
            7,
            'stdout',
            'stderr',
        );

        self::assertFalse($process->isRunning());
        self::assertSame(7, $process->wait());
        self::assertSame(7, $process->wait());
        self::assertSame('stdout', $process->output());
        self::assertSame('stderr', $process->errorOutput());
    }

    public function test_stop_is_a_signed_control_and_wait_observes_cancellation(): void
    {
        [$process, $control] = $this->process();
        $control->cancelOnStop = true;
        $process->start();
        $process->stop(0.5);

        self::assertSame(['start', 'stop'], array_column($control->dispatched, 'action'));
        self::assertSame(143, $process->wait());
        self::assertStringContainsString('cancelled before launch', $process->errorOutput());
    }

    public function test_expired_or_missing_control_state_fails_without_waiting_indefinitely(): void
    {
        [$expired, $control] = $this->process();
        $expired->start();
        $control->worker = new RemoteWorkerState(
            'pending',
            'agent-a',
            self::RUN_ID,
            'p1',
            1_699_999_999_999,
        );

        self::assertFalse($expired->isRunning());
        self::assertSame(1, $expired->exitCode());
        self::assertStringContainsString('control message expired', $expired->errorOutput());

        [$missing, $missingControl] = $this->process();
        $missing->start();
        $missingControl->worker = null;
        self::assertFalse($missing->isRunning());
        self::assertSame(1, $missing->wait());
        self::assertStringContainsString('state disappeared', $missing->errorOutput());
    }

    public function test_wait_is_bounded_when_an_agent_never_reaches_terminal_state(): void
    {
        $clock = new ControlledWorkerControlClock;
        [$process] = $this->process($clock, shutdownTimeoutMs: 100, pollIntervalMs: 25);
        $process->start();

        self::assertSame(1, $process->wait());
        self::assertSame([25, 25, 25, 25], $clock->sleeps);
        self::assertStringContainsString('shutdown timeout', $process->errorOutput());
    }

    public function test_start_and_wait_reject_invalid_lifecycle_calls(): void
    {
        [$process] = $this->process();

        try {
            $process->wait();
            self::fail('Expected wait-before-start to fail.');
        } catch (LogicException) {
            self::assertTrue(true);
        }

        $process->start();

        $this->expectException(LogicException::class);
        $process->start();
    }

    public function test_start_recovers_when_dispatch_committed_before_the_connection_failed(): void
    {
        [$process, $control] = $this->process();
        $control->failDispatchAfterState = true;

        $process->start();

        self::assertTrue($process->isRunning());
        self::assertSame(['start'], array_column($control->dispatched, 'action'));
    }

    /**
     * @return array{RemoteWorkerProcess, ProcessControlPlane}
     */
    private function process(
        ?ControlledWorkerControlClock $clock = null,
        int $shutdownTimeoutMs = 2_000,
        int $pollIntervalMs = 5,
    ): array {
        $clock ??= new ControlledWorkerControlClock;
        $config = new RemoteWorkerConfiguration(
            'default',
            'raceproof:remote',
            '0123456789abcdef0123456789abcdef',
            ['agent-a'],
            15_000,
            2_000,
            $pollIntervalMs,
            300,
            5_000,
            $shutdownTimeoutMs,
            8,
            2_048,
            4_096,
        );
        $control = new ProcessControlPlane;
        $codec = new RemoteControlMessageCodec($config, $clock);

        return [
            new RemoteWorkerProcess(
                self::RUN_ID,
                'p1',
                'agent-a',
                $control,
                $codec,
                $config,
                $clock,
            ),
            $control,
        ];
    }
}

final class ProcessControlPlane implements WorkerControlPlane
{
    /** @var list<array{action: string, envelope: string}> */
    public array $dispatched = [];

    public ?RemoteWorkerState $worker = null;

    public bool $cancelOnStop = false;

    public bool $failDispatchAfterState = false;

    public function healthCheck(): void {}

    public function heartbeat(string $agentId): void {}

    public function agentAvailable(string $agentId): bool
    {
        return true;
    }

    public function dispatch(RemoteControlMessage $message, string $envelope): void
    {
        $this->dispatched[] = ['action' => $message->action, 'envelope' => $envelope];

        if ($message->action === RemoteControlMessage::ACTION_START) {
            $this->worker = new RemoteWorkerState(
                'pending',
                $message->agentId,
                $message->runId,
                $message->participantId,
                $message->expiresAtMs,
            );

            if ($this->failDispatchAfterState) {
                throw new RemoteControlUnavailable(
                    'RaceProof remote worker control plane is unavailable or misconfigured.',
                );
            }
        } elseif ($this->cancelOnStop) {
            $this->worker = new RemoteWorkerState(
                'cancelled',
                $message->agentId,
                $message->runId,
                $message->participantId,
                $message->expiresAtMs,
                143,
                '',
                'Remote worker was cancelled before launch.',
            );
        }
    }

    public function next(string $agentId, string $action): ?string
    {
        return null;
    }

    public function claim(RemoteControlMessage $message): bool
    {
        return true;
    }

    public function markRunning(RemoteControlMessage $message): bool
    {
        return true;
    }

    public function finish(
        string $runId,
        string $participantId,
        int $exitCode,
        string $output,
        string $errorOutput,
        bool $stopped,
    ): void {}

    public function state(string $runId, string $participantId): ?RemoteWorkerState
    {
        return $this->worker;
    }
}
