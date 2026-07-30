<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RedisWorkerControlPlane;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Tests\Support\ControlledWorkerControlClock;
use RaceProof\Laravel\Tests\Support\InMemoryRedisClient;
use RuntimeException;

final class RedisWorkerControlPlaneTest extends TestCase
{
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_it_probes_health_and_tracks_bounded_agent_heartbeats(): void
    {
        $client = new InMemoryRedisClient;
        $control = new RedisWorkerControlPlane($client, $this->config());

        self::assertFalse($control->agentAvailable('agent-a'));
        $control->healthCheck();
        $control->heartbeat('agent-a');
        self::assertTrue($control->agentAvailable('agent-a'));
        self::assertSame(['get', 'ping', 'get'], array_column($client->commands, 'command'));
        self::assertContains('remote-health', $client->scripts);
        self::assertContains('remote-heartbeat', $client->scripts);
    }

    public function test_health_rejects_a_failed_ping_before_writing_a_probe(): void
    {
        $client = new InMemoryRedisClient;
        $client->commandOverrides['ping'] = false;
        $control = new RedisWorkerControlPlane($client, $this->config());

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('health check failed');

        $control->healthCheck();
    }

    public function test_it_dispatches_claims_runs_and_finishes_a_worker_once(): void
    {
        $client = new InMemoryRedisClient;
        $clock = new ControlledWorkerControlClock;
        $config = $this->config();
        $codec = new RemoteControlMessageCodec($config, $clock);
        $control = new RedisWorkerControlPlane($client, $config);
        $issued = $codec->issue('start', 'agent-a', self::RUN_ID, 'p1');

        $control->dispatch($issued['message'], $issued['envelope']);
        $pending = $control->state(self::RUN_ID, 'p1');
        self::assertNotNull($pending);
        self::assertSame('pending', $pending->status);

        $encoded = $control->next('agent-a', 'start');
        self::assertIsString($encoded);
        $message = $codec->decode($encoded, 'agent-a');
        self::assertTrue($control->claim($message));
        self::assertFalse($control->claim($message));
        self::assertTrue($control->markRunning($message));
        self::assertSame('running', $control->state(self::RUN_ID, 'p1')?->status);

        $control->finish(self::RUN_ID, 'p1', 0, 'worker output', '', false);
        $terminal = $control->state(self::RUN_ID, 'p1');
        self::assertNotNull($terminal);
        self::assertTrue($terminal->terminal());
        self::assertSame('completed', $terminal->status);
        self::assertSame(0, $terminal->exitCode);
        self::assertSame('worker output', $terminal->output);
        self::assertNull($control->next('agent-a', 'start'));
    }

    public function test_signed_stop_cancels_an_unclaimed_start_and_replay_cannot_launch_it(): void
    {
        $client = new InMemoryRedisClient;
        $clock = new ControlledWorkerControlClock;
        $config = $this->config();
        $codec = new RemoteControlMessageCodec($config, $clock);
        $control = new RedisWorkerControlPlane($client, $config);
        $start = $codec->issue('start', 'agent-a', self::RUN_ID, 'p1');
        $stop = $codec->issue('stop', 'agent-a', self::RUN_ID, 'p1');

        $control->dispatch($start['message'], $start['envelope']);
        $control->dispatch($stop['message'], $stop['envelope']);
        $encodedStop = $control->next('agent-a', 'stop');
        self::assertIsString($encodedStop);
        self::assertTrue($control->claim($codec->decode($encodedStop, 'agent-a')));

        $cancelled = $control->state(self::RUN_ID, 'p1');
        self::assertNotNull($cancelled);
        self::assertSame('cancelled', $cancelled->status);
        self::assertSame(143, $cancelled->exitCode);

        $encodedStart = $control->next('agent-a', 'start');
        self::assertIsString($encodedStart);
        self::assertFalse($control->claim($codec->decode($encodedStart, 'agent-a')));
    }

    public function test_it_rejects_collisions_missing_state_and_oversized_output(): void
    {
        $client = new InMemoryRedisClient;
        $clock = new ControlledWorkerControlClock;
        $config = $this->config(outputBytes: 8);
        $codec = new RemoteControlMessageCodec($config, $clock);
        $control = new RedisWorkerControlPlane($client, $config);
        $start = $codec->issue('start', 'agent-a', self::RUN_ID, 'p1');

        $control->dispatch($start['message'], $start['envelope']);

        foreach (
            [
                fn () => $control->dispatch($start['message'], $start['envelope']),
                fn () => $control->finish(str_repeat('b', 32), 'p1', 1, '', '', false),
                fn () => $control->finish(self::RUN_ID, 'p1', 1, '123456789', '', false),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected invalid remote control state to fail.');
            } catch (RaceProofException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_it_rejects_malformed_state_and_redacts_client_failures(): void
    {
        $client = new InMemoryRedisClient;
        $config = $this->config();
        $control = new RedisWorkerControlPlane($client, $config);
        $stateKey = 'raceproof:remote:worker:'.self::RUN_ID.':p1';

        foreach (
            [
                ['status' => 'running'],
                [
                    'status' => 'running',
                    'agent_id' => 'agent-a',
                    'run_id' => self::RUN_ID,
                    'participant_id' => 'p1',
                    'expires_at_ms' => '1700000015000',
                    'output' => '',
                    'error_output' => '',
                    'unexpected' => 'field',
                ],
                [
                    'status' => 'running',
                    'agent_id' => 'agent-a',
                    'run_id' => self::RUN_ID,
                    'participant_id' => 'p1',
                    'expires_at_ms' => '1700000015000',
                    'exit_code' => '0',
                    'output' => '',
                    'error_output' => '',
                ],
            ] as $invalidState
        ) {
            $client->hashes[$stateKey] = $invalidState;

            try {
                $control->state(self::RUN_ID, 'p1');
                self::fail('Expected malformed remote state to fail.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'RaceProof remote worker control plane returned invalid state.',
                    $exception->getMessage(),
                );
            }
        }

        $secret = 'redis://raceproof:super-secret@example.test';
        $client->failure = new RuntimeException($secret);

        try {
            $control->healthCheck();
            self::fail('Expected Redis failure to be wrapped.');
        } catch (RaceProofException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
            self::assertSame(
                'RaceProof remote worker control plane is unavailable or misconfigured.',
                $exception->getMessage(),
            );
        }
    }

    public function test_pending_control_inboxes_have_an_explicit_fail_closed_cap(): void
    {
        $client = new InMemoryRedisClient;
        $clock = new ControlledWorkerControlClock;
        $config = $this->config(maxPendingControls: 1);
        $codec = new RemoteControlMessageCodec($config, $clock);
        $control = new RedisWorkerControlPlane($client, $config);
        $first = $codec->issue('start', 'agent-a', self::RUN_ID, 'p1');
        $second = $codec->issue('start', 'agent-a', str_repeat('b', 32), 'p1');
        $control->dispatch($first['message'], $first['envelope']);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('control state is unavailable');

        $control->dispatch($second['message'], $second['envelope']);
    }

    private function config(
        int $outputBytes = 4_096,
        int $maxPendingControls = 1_000,
    ): RemoteWorkerConfiguration {
        return new RemoteWorkerConfiguration(
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
            8,
            2_048,
            $outputBytes,
            100,
            $maxPendingControls,
        );
    }
}
