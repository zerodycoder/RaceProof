<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RedisSynchronizedParticipantClock;
use RaceProof\Laravel\Tests\Support\InMemoryRedisClient;
use RuntimeException;

final class RedisSynchronizedParticipantClockTest extends TestCase
{
    public function test_it_aligns_a_monotonic_clock_to_one_bounded_redis_time_sample(): void
    {
        $client = new InMemoryRedisClient;
        $client->commandOverrides['time'] = ['1700000000', '123456'];
        $monotonic = new ParticipantRaceClock([
            1_000_000_000,
            1_002_000_000,
            1_003_000_000,
            1_004_000_000,
        ]);
        $clock = new RedisSynchronizedParticipantClock($client, $monotonic, 100);
        $redisNs = 1_700_000_000_123_456_000;

        self::assertSame($redisNs + 2_000_000, $clock->nowNs());
        self::assertSame($redisNs + 3_000_000, $clock->nowNs());
        self::assertSame(1, count(array_filter(
            $client->commands,
            static fn (array $command): bool => $command['command'] === 'time',
        )));
    }

    public function test_it_rejects_invalid_time_excessive_rtt_and_client_failures_without_leaking_details(): void
    {
        $invalid = new InMemoryRedisClient;
        $invalid->commandOverrides['time'] = ['bad', 'time'];
        $slow = new InMemoryRedisClient;
        $slow->commandOverrides['time'] = ['1700000000', '1'];
        $failed = new InMemoryRedisClient;
        $secret = 'redis://raceproof:super-secret@example.test';
        $failed->failure = new RuntimeException($secret);

        foreach (
            [
                new RedisSynchronizedParticipantClock($invalid, new ParticipantRaceClock([1, 2]), 100),
                new RedisSynchronizedParticipantClock($slow, new ParticipantRaceClock([0, 101_000_000]), 100),
                new RedisSynchronizedParticipantClock($failed, new ParticipantRaceClock([1, 2]), 100),
            ] as $clock
        ) {
            try {
                $clock->nowNs();
                self::fail('Expected remote participant clock synchronization to fail.');
            } catch (RaceProofException $exception) {
                self::assertStringContainsString('clock synchronization', $exception->getMessage());
                self::assertStringNotContainsString($secret, $exception->getMessage());
                self::assertStringNotContainsString('super-secret', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_an_unbounded_rtt_configuration(): void
    {
        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('configuration is invalid');

        new RedisSynchronizedParticipantClock(new InMemoryRedisClient, new ParticipantRaceClock([0]), 0);
    }
}

final class ParticipantRaceClock implements RaceClock
{
    /** @param list<int> $times */
    public function __construct(private array $times) {}

    public function nowNs(): int
    {
        return array_shift($this->times) ?? 0;
    }

    public function sleepMilliseconds(int $milliseconds): void {}
}
