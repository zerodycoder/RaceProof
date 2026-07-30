<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use RaceProof\Laravel\Contracts\ParticipantClock;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Coordination\RedisClient;
use RaceProof\Laravel\Exceptions\RaceProofException;
use Throwable;

/**
 * @internal Aligns each host's monotonic clock to one Redis server-time sample.
 */
final class RedisSynchronizedParticipantClock implements ParticipantClock
{
    private ?int $offsetNs = null;

    public function __construct(
        private readonly RedisClient $client,
        private readonly RaceClock $clock,
        private readonly int $maxRoundTripMs,
    ) {
        if ($maxRoundTripMs < 1 || $maxRoundTripMs > 1_000) {
            throw new RaceProofException('RaceProof remote clock synchronization configuration is invalid.');
        }
    }

    public function nowNs(): int
    {
        if ($this->offsetNs === null) {
            $this->synchronize();
        }

        return $this->clock->nowNs() + ($this->offsetNs ?? 0);
    }

    private function synchronize(): void
    {
        try {
            $before = $this->clock->nowNs();
            $time = $this->client->command('time');
            $after = $this->clock->nowNs();
        } catch (Throwable) {
            throw new RaceProofException('RaceProof remote participant clock synchronization failed.');
        }

        if (
            ! is_array($time)
            || count($time) !== 2
            || ! isset($time[0], $time[1])
            || ! is_int($time[0]) && ! is_string($time[0])
            || ! is_int($time[1]) && ! is_string($time[1])
            || preg_match('/^[0-9]+$/D', (string) $time[0]) !== 1
            || preg_match('/^[0-9]{1,6}$/D', (string) $time[1]) !== 1
        ) {
            throw new RaceProofException('RaceProof remote participant clock synchronization returned invalid time.');
        }

        $roundTripNs = $after - $before;

        if ($roundTripNs < 0 || $roundTripNs > $this->maxRoundTripMs * 1_000_000) {
            throw new RaceProofException('RaceProof remote participant clock synchronization exceeded its RTT limit.');
        }

        $redisNs = ((int) $time[0] * 1_000_000_000) + ((int) $time[1] * 1_000);
        $midpointNs = $before + intdiv($roundTripNs, 2);
        $this->offsetNs = $redisNs - $midpointNs;
    }
}
