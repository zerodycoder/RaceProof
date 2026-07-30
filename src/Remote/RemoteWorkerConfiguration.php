<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\ConfigValue;

/**
 * @internal
 */
final readonly class RemoteWorkerConfiguration
{
    private const AGENT_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/D';

    /**
     * @param  list<string>  $agents
     */
    public function __construct(
        public string $connection,
        public string $namespace,
        public string $secret,
        public array $agents,
        public int $messageTtlMs,
        public int $maxClockSkewMs,
        public int $pollIntervalMs,
        public int $stateTtlSeconds,
        public int $heartbeatTtlMs,
        public int $shutdownTimeoutMs,
        public int $maxConcurrency,
        public int $controlMessageBytes,
        public int $outputBytes,
        public int $clockSyncMaxRttMs = 100,
        public int $maxPendingControls = 1_000,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $connection) !== 1) {
            throw new RaceProofException('RaceProof remote Redis connection name configuration is invalid.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,127}$/D', $namespace) !== 1) {
            throw new RaceProofException('RaceProof remote namespace configuration is invalid.');
        }

        if (strlen($secret) < 32 || strlen($secret) > 1_024 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw new RaceProofException('RaceProof remote authentication secret configuration is invalid.');
        }

        if ($agents === [] || count($agents) > 32 || count(array_unique($agents)) !== count($agents)) {
            throw new RaceProofException('RaceProof remote agent configuration is invalid.');
        }

        foreach ($agents as $agent) {
            if (preg_match(self::AGENT_PATTERN, $agent) !== 1) {
                throw new RaceProofException('RaceProof remote agent configuration is invalid.');
            }
        }

        $this->range($messageTtlMs, 1_000, 60_000, 'message TTL');
        $this->range($maxClockSkewMs, 0, 5_000, 'clock skew');
        $this->range($pollIntervalMs, 1, 1_000, 'poll interval');
        $this->range($stateTtlSeconds, 60, 604_800, 'state TTL');
        $this->range($heartbeatTtlMs, 1_000, 60_000, 'heartbeat TTL');
        $this->range($shutdownTimeoutMs, 100, 10_000, 'shutdown timeout');
        $this->range($maxConcurrency, 1, 100, 'agent concurrency');
        $this->range($controlMessageBytes, 512, 8_192, 'control message byte limit');
        $this->range($outputBytes, 0, 65_536, 'output byte limit');
        $this->range($clockSyncMaxRttMs, 1, 1_000, 'clock synchronization RTT');
        $this->range($maxPendingControls, 1, 10_000, 'pending control limit');

        if (
            $maxClockSkewMs >= $messageTtlMs
            || $heartbeatTtlMs < $pollIntervalMs * 3
        ) {
            throw new RaceProofException('RaceProof remote timing configuration is invalid.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            ConfigValue::string($config, 'raceproof.coordinator.redis.connection'),
            ConfigValue::string($config, 'raceproof.worker_transport.remote.namespace'),
            ConfigValue::string($config, 'raceproof.worker_transport.remote.secret'),
            ConfigValue::stringList($config, 'raceproof.worker_transport.remote.agents'),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.message_ttl_ms', 15_000),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.max_clock_skew_ms', 2_000),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.poll_interval_ms', 25),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.state_ttl_seconds', 300),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.heartbeat_ttl_ms', 5_000),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.shutdown_timeout_ms', 2_000),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.max_concurrency', 8),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.control_message_bytes', 2_048),
            ConfigValue::integer($config, 'raceproof.worker_transport.remote.output_bytes', 4_096),
            ConfigValue::integer(
                $config,
                'raceproof.worker_transport.remote.clock_sync_max_rtt_ms',
                100,
            ),
            ConfigValue::integer(
                $config,
                'raceproof.worker_transport.remote.max_pending_controls',
                1_000,
            ),
        );
    }

    public function agentFor(string $participantId): string
    {
        if (preg_match('/^p([1-9]|[1-9][0-9]|100)$/D', $participantId, $matches) !== 1) {
            throw new RaceProofException('RaceProof remote participant identifier is invalid.');
        }

        return $this->agents[((int) $matches[1] - 1) % count($this->agents)];
    }

    public function assertAgent(string $agentId): void
    {
        if (! in_array($agentId, $this->agents, true)) {
            throw new RaceProofException('RaceProof remote agent is not registered.');
        }
    }

    public function retentionMilliseconds(): int
    {
        return $this->stateTtlSeconds * 1_000;
    }

    private function range(int $value, int $minimum, int $maximum, string $name): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new RaceProofException("RaceProof remote {$name} configuration is invalid.");
        }
    }
}
