<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;

final class RemoteWorkerConfigurationTest extends TestCase
{
    public function test_it_routes_participants_deterministically_across_registered_agents(): void
    {
        $config = $this->config(agents: ['agent-a', 'agent-b']);

        self::assertSame('agent-a', $config->agentFor('p1'));
        self::assertSame('agent-b', $config->agentFor('p2'));
        self::assertSame('agent-a', $config->agentFor('p3'));
        self::assertSame('agent-b', $config->agentFor('p100'));
        self::assertSame(300_000, $config->retentionMilliseconds());
        $config->assertAgent('agent-b');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'connection URL' => [['connection' => 'redis://secret@example.test']];
        yield 'namespace whitespace' => [['namespace' => 'remote controls']];
        yield 'short secret' => [['secret' => 'too-short']];
        yield 'control character secret' => [['secret' => str_repeat('a', 31)."\n"]];
        yield 'no agents' => [['agents' => []]];
        yield 'duplicate agents' => [['agents' => ['agent-a', 'agent-a']]];
        yield 'invalid agent' => [['agents' => ['../../agent']]];
        yield 'message TTL' => [['messageTtlMs' => 999]];
        yield 'clock skew' => [['maxClockSkewMs' => 15_000]];
        yield 'poll interval' => [['pollIntervalMs' => 0]];
        yield 'state TTL' => [['stateTtlSeconds' => 59]];
        yield 'heartbeat TTL' => [['heartbeatTtlMs' => 1_000, 'pollIntervalMs' => 1_000]];
        yield 'heartbeat safety margin' => [['heartbeatTtlMs' => 2_999, 'pollIntervalMs' => 1_000]];
        yield 'shutdown timeout' => [['shutdownTimeoutMs' => 10_001]];
        yield 'concurrency' => [['maxConcurrency' => 0]];
        yield 'message bytes' => [['controlMessageBytes' => 511]];
        yield 'output bytes' => [['outputBytes' => 65_537]];
        yield 'clock synchronization RTT' => [['clockSyncMaxRttMs' => 0]];
        yield 'pending controls' => [['maxPendingControls' => 10_001]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidConfiguration')]
    public function test_it_rejects_invalid_configuration_without_echoing_values(array $override): void
    {
        $secret = 'redis://raceproof:super-secret@example.test';

        try {
            $this->config(...$override);
            self::fail('Expected remote worker configuration to be rejected.');
        } catch (RaceProofException $exception) {
            self::assertStringContainsString('configuration is invalid', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }

    public function test_it_rejects_unknown_agents_and_participant_identifiers(): void
    {
        $config = $this->config();

        foreach (
            [
                fn () => $config->assertAgent('agent-b'),
                fn () => $config->agentFor('admin'),
                fn () => $config->agentFor('p0'),
                fn () => $config->agentFor('p101'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected identifier validation to fail.');
            } catch (RaceProofException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * @param  list<string>  $agents
     */
    private function config(
        string $connection = 'default',
        string $namespace = 'raceproof:remote',
        ?string $secret = null,
        array $agents = ['agent-a'],
        int $messageTtlMs = 15_000,
        int $maxClockSkewMs = 2_000,
        int $pollIntervalMs = 5,
        int $stateTtlSeconds = 300,
        int $heartbeatTtlMs = 5_000,
        int $shutdownTimeoutMs = 2_000,
        int $maxConcurrency = 8,
        int $controlMessageBytes = 2_048,
        int $outputBytes = 4_096,
        int $clockSyncMaxRttMs = 100,
        int $maxPendingControls = 1_000,
    ): RemoteWorkerConfiguration {
        return new RemoteWorkerConfiguration(
            $connection,
            $namespace,
            $secret ?? str_repeat('raceproof-', 4),
            $agents,
            $messageTtlMs,
            $maxClockSkewMs,
            $pollIntervalMs,
            $stateTtlSeconds,
            $heartbeatTtlMs,
            $shutdownTimeoutMs,
            $maxConcurrency,
            $controlMessageBytes,
            $outputBytes,
            $clockSyncMaxRttMs,
            $maxPendingControls,
        );
    }
}
