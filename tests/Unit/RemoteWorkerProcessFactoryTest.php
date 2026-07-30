<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RemoteControlMessage;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Remote\RemoteWorkerProcess;
use RaceProof\Laravel\Remote\RemoteWorkerProcessFactory;
use RaceProof\Laravel\Remote\RemoteWorkerState;
use RaceProof\Laravel\Tests\Support\ControlledWorkerControlClock;

final class RemoteWorkerProcessFactoryTest extends TestCase
{
    public function test_it_routes_participants_and_reports_remote_health(): void
    {
        $control = new FactoryControlPlane;
        $factory = $this->factory($control);

        self::assertSame('remote', $factory->driver());
        self::assertInstanceOf(RemoteWorkerProcess::class, $factory->create('run-1', 'p2'));

        $factory->healthCheck();

        self::assertSame(1, $control->healthChecks);
        self::assertSame(['agent-b', 'agent-a', 'agent-b'], $control->availabilityChecks);
    }

    public function test_it_rejects_an_unavailable_selected_agent(): void
    {
        $control = new FactoryControlPlane;
        $control->available['agent-b'] = false;
        $factory = $this->factory($control);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('agent-b');

        $factory->create('run-1', 'p2');
    }

    public function test_health_check_rejects_any_unavailable_configured_agent(): void
    {
        $control = new FactoryControlPlane;
        $control->available['agent-b'] = false;
        $factory = $this->factory($control);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('agent-b');

        $factory->healthCheck();
    }

    private function factory(FactoryControlPlane $control): RemoteWorkerProcessFactory
    {
        $clock = new ControlledWorkerControlClock;
        $config = new RemoteWorkerConfiguration(
            'default',
            'raceproof:remote',
            '0123456789abcdef0123456789abcdef',
            ['agent-a', 'agent-b'],
            15_000,
            2_000,
            5,
            300,
            5_000,
            2_000,
            8,
            2_048,
            4_096,
        );

        return new RemoteWorkerProcessFactory(
            $config,
            $control,
            new RemoteControlMessageCodec($config, $clock),
            $clock,
        );
    }
}

final class FactoryControlPlane implements WorkerControlPlane
{
    /** @var array<string, bool> */
    public array $available = [
        'agent-a' => true,
        'agent-b' => true,
    ];

    /** @var list<string> */
    public array $availabilityChecks = [];

    public int $healthChecks = 0;

    public function healthCheck(): void
    {
        $this->healthChecks++;
    }

    public function heartbeat(string $agentId): void {}

    public function agentAvailable(string $agentId): bool
    {
        $this->availabilityChecks[] = $agentId;

        return $this->available[$agentId] ?? false;
    }

    public function dispatch(RemoteControlMessage $message, string $envelope): void {}

    public function next(string $agentId, string $action): ?string
    {
        return null;
    }

    public function claim(RemoteControlMessage $message): bool
    {
        return false;
    }

    public function markRunning(RemoteControlMessage $message): bool
    {
        return false;
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
        return null;
    }
}
