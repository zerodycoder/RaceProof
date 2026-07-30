<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Mockery;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\LocalWorkerProcessFactory;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Remote\RemoteControlMessage;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerAgent;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Remote\RemoteWorkerState;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use RaceProof\Laravel\Tests\Support\ControlledWorkerControlClock;

final class RemoteWorkerAgentTest extends TestCase
{
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('raceproof.worker_transport.driver', 'remote');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_launches_a_valid_control_and_stores_bounded_redacted_terminal_evidence(): void
    {
        $process = new AgentWorkerProcess(
            running: false,
            exitCode: 7,
            output: 'token=worker-secret '.str_repeat('x', 80),
            errorOutput: 'Authorization: Bearer private-token',
        );
        [$agent, $control, $codec] = $this->agent(['p1' => $process], outputBytes: 48);
        $control->enqueue($codec->issue('start', 'agent-a', self::RUN_ID, 'p1'));
        $warnings = [];

        self::assertTrue($agent->tick('agent-a', function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        }));

        $terminal = $control->state(self::RUN_ID, 'p1');
        self::assertNotNull($terminal);
        self::assertSame('failed', $terminal->status);
        self::assertSame(7, $terminal->exitCode);
        self::assertLessThanOrEqual(48, strlen($terminal->output));
        self::assertLessThanOrEqual(48, strlen($terminal->errorOutput));
        self::assertStringContainsString('[REDACTED]', $terminal->output);
        self::assertStringContainsString('[REDACTED]', $terminal->errorOutput);
        self::assertStringNotContainsString('worker-secret', $terminal->output);
        self::assertStringNotContainsString('private-token', $terminal->errorOutput);
        self::assertSame([], $warnings);
        self::assertSame(1, $process->startCalls);
        self::assertSame(1, $process->waitCalls);
    }

    public function test_stop_controls_take_priority_and_stop_a_running_worker(): void
    {
        $process = new AgentWorkerProcess(running: true, exitCode: 143);
        [$agent, $control, $codec] = $this->agent(['p1' => $process]);
        $control->enqueue($codec->issue('start', 'agent-a', self::RUN_ID, 'p1'));
        $warning = static function (string $message): void {};

        $agent->tick('agent-a', $warning);
        self::assertSame('running', $control->state(self::RUN_ID, 'p1')?->status);

        $control->enqueue($codec->issue('stop', 'agent-a', self::RUN_ID, 'p1'));
        $agent->tick('agent-a', $warning);

        self::assertSame(1, $process->stopCalls);
        self::assertSame('stopped', $control->state(self::RUN_ID, 'p1')?->status);
        self::assertSame(143, $control->state(self::RUN_ID, 'p1')?->exitCode);
    }

    public function test_capacity_is_bounded_and_queued_starts_are_not_dropped(): void
    {
        $first = new AgentWorkerProcess(running: true);
        $second = new AgentWorkerProcess(running: true);
        [$agent, $control, $codec] = $this->agent(
            ['p1' => $first, 'p2' => $second],
            maxConcurrency: 1,
        );
        $control->enqueue($codec->issue('start', 'agent-a', self::RUN_ID, 'p1'));
        $control->enqueue($codec->issue('start', 'agent-a', self::RUN_ID, 'p2'));
        $warning = static function (string $message): void {};

        $agent->tick('agent-a', $warning);
        self::assertSame(1, $first->startCalls);
        self::assertSame(0, $second->startCalls);
        self::assertSame(1, $control->queuedStarts());

        $first->running = false;
        $agent->tick('agent-a', $warning);
        $agent->tick('agent-a', $warning);
        self::assertSame(1, $second->startCalls);
        self::assertSame(0, $control->queuedStarts());
    }

    public function test_it_does_not_acknowledge_a_stop_after_losing_the_local_process_handle(): void
    {
        [$agent, $control, $codec] = $this->agent([]);
        $start = $codec->issue('start', 'agent-a', self::RUN_ID, 'p1');
        $control->enqueue($start);
        $encoded = $control->next('agent-a', 'start');
        self::assertIsString($encoded);
        $message = $codec->decode($encoded, 'agent-a');
        self::assertTrue($control->claim($message));
        self::assertTrue($control->markRunning($message));
        $control->enqueue($codec->issue('stop', 'agent-a', self::RUN_ID, 'p1'));
        $warnings = [];

        $agent->tick('agent-a', function (string $warning) use (&$warnings): void {
            $warnings[] = $warning;
        });

        self::assertSame('stop_requested', $control->state(self::RUN_ID, 'p1')?->status);
        self::assertNull($control->state(self::RUN_ID, 'p1')?->exitCode);
        self::assertSame(
            ['Remote worker stop control has no local process handle.'],
            $warnings,
        );
    }

    public function test_invalid_controls_are_rejected_without_launching_or_exposing_payloads(): void
    {
        $process = new AgentWorkerProcess(running: true);
        [$agent, $control] = $this->agent(['p1' => $process]);
        $control->startQueue[] = '{"token":"remote-super-secret"}';
        $warnings = [];

        $agent->tick('agent-a', function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        self::assertSame(0, $process->startCalls);
        self::assertSame(['Rejected an invalid remote worker start control.'], $warnings);
        self::assertStringNotContainsString('remote-super-secret', implode('', $warnings));
    }

    /**
     * @param  array<string, AgentWorkerProcess>  $processes
     * @return array{RemoteWorkerAgent, AgentControlPlane, RemoteControlMessageCodec}
     */
    private function agent(
        array $processes,
        int $outputBytes = 4_096,
        int $maxConcurrency = 8,
    ): array {
        $clock = new ControlledWorkerControlClock;
        $config = new RemoteWorkerConfiguration(
            'default',
            'raceproof:remote',
            '0123456789abcdef0123456789abcdef',
            ['agent-a'],
            15_000,
            2_000,
            5,
            300,
            5_000,
            2_000,
            $maxConcurrency,
            2_048,
            $outputBytes,
        );
        $codec = new RemoteControlMessageCodec($config, $clock);
        $control = new AgentControlPlane;
        $coordinator = Mockery::mock(CoordinatorStore::class);
        $coordinator->shouldReceive('driver')->andReturn('redis');
        $factory = new AgentWorkerProcessFactory($processes);

        return [
            new RemoteWorkerAgent(
                $this->app['config'],
                $config,
                $coordinator,
                $control,
                $codec,
                $factory,
                $clock,
                $this->app->make(EnvironmentGuard::class),
                $this->app->make(DatabaseSafety::class),
                $this->app->make(SensitiveDataRedactor::class),
            ),
            $control,
            $codec,
        ];
    }
}

final class AgentControlPlane implements WorkerControlPlane
{
    /** @var list<string> */
    public array $startQueue = [];

    /** @var list<string> */
    public array $stopQueue = [];

    /** @var array<string, RemoteWorkerState> */
    private array $states = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @param array{message: RemoteControlMessage, envelope: string} $issued */
    public function enqueue(array $issued): void
    {
        $message = $issued['message'];
        $this->states[$this->key($message->runId, $message->participantId)] ??= new RemoteWorkerState(
            'pending',
            $message->agentId,
            $message->runId,
            $message->participantId,
            $message->expiresAtMs,
        );

        if ($message->action === 'start') {
            $this->startQueue[] = $issued['envelope'];
        } else {
            $this->stopQueue[] = $issued['envelope'];
        }
    }

    public function queuedStarts(): int
    {
        return count($this->startQueue);
    }

    public function healthCheck(): void {}

    public function heartbeat(string $agentId): void {}

    public function agentAvailable(string $agentId): bool
    {
        return true;
    }

    public function dispatch(RemoteControlMessage $message, string $envelope): void {}

    public function next(string $agentId, string $action): ?string
    {
        $queue = $action === 'start' ? 'startQueue' : 'stopQueue';

        return array_shift($this->{$queue});
    }

    public function claim(RemoteControlMessage $message): bool
    {
        if (isset($this->seen[$message->messageId])) {
            return false;
        }

        $this->seen[$message->messageId] = true;
        $key = $this->key($message->runId, $message->participantId);
        $state = $this->states[$key];

        if ($message->action === 'start') {
            if ($state->status !== 'pending') {
                return false;
            }

            $this->states[$key] = new RemoteWorkerState(
                'claimed',
                $state->agentId,
                $state->runId,
                $state->participantId,
                $state->expiresAtMs,
            );

            return true;
        }

        $this->states[$key] = new RemoteWorkerState(
            $state->status === 'pending' ? 'cancelled' : 'stop_requested',
            $state->agentId,
            $state->runId,
            $state->participantId,
            $state->expiresAtMs,
            $state->status === 'pending' ? 143 : null,
            '',
            $state->status === 'pending' ? 'Remote worker was cancelled before launch.' : '',
        );

        return true;
    }

    public function markRunning(RemoteControlMessage $message): bool
    {
        $key = $this->key($message->runId, $message->participantId);
        $state = $this->states[$key];

        if ($state->status !== 'claimed') {
            return false;
        }

        $this->states[$key] = new RemoteWorkerState(
            'running',
            $state->agentId,
            $state->runId,
            $state->participantId,
            $state->expiresAtMs,
        );

        return true;
    }

    public function finish(
        string $runId,
        string $participantId,
        int $exitCode,
        string $output,
        string $errorOutput,
        bool $stopped,
    ): void {
        $key = $this->key($runId, $participantId);
        $state = $this->states[$key];
        $this->states[$key] = new RemoteWorkerState(
            $stopped ? 'stopped' : ($exitCode === 0 ? 'completed' : 'failed'),
            $state->agentId,
            $runId,
            $participantId,
            $state->expiresAtMs,
            $exitCode,
            $output,
            $errorOutput,
        );
    }

    public function state(string $runId, string $participantId): ?RemoteWorkerState
    {
        return $this->states[$this->key($runId, $participantId)] ?? null;
    }

    private function key(string $runId, string $participantId): string
    {
        return "{$runId}:{$participantId}";
    }
}

final readonly class AgentWorkerProcessFactory implements LocalWorkerProcessFactory
{
    /** @param array<string, AgentWorkerProcess> $processes */
    public function __construct(private array $processes) {}

    public function create(string $runId, string $participantId): WorkerProcess
    {
        return $this->processes[$participantId];
    }

    public function driver(): string
    {
        return 'local';
    }

    public function healthCheck(): void {}
}

final class AgentWorkerProcess implements WorkerProcess
{
    public int $startCalls = 0;

    public int $stopCalls = 0;

    public int $waitCalls = 0;

    public function __construct(
        public bool $running,
        private readonly int $exitCode = 0,
        private readonly string $output = '',
        private readonly string $errorOutput = '',
    ) {}

    public function start(): void
    {
        $this->startCalls++;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stop(float $timeoutSeconds): void
    {
        $this->stopCalls++;
        $this->running = false;
    }

    public function wait(): int
    {
        $this->waitCalls++;

        return $this->exitCode;
    }

    public function exitCode(): ?int
    {
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
}
