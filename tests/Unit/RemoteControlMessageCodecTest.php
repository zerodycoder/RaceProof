<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Remote\RemoteControlMessage;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Tests\Support\ControlledWorkerControlClock;

final class RemoteControlMessageCodecTest extends TestCase
{
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_it_round_trips_a_canonical_signed_message_without_serializing_the_secret(): void
    {
        $secret = self::authenticationSecret();
        $clock = new ControlledWorkerControlClock;
        $codec = new RemoteControlMessageCodec($this->config(secret: $secret), $clock);
        $message = new RemoteControlMessage(
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            $clock->nowMs,
            $clock->nowMs + 15_000,
            RemoteControlMessage::ACTION_START,
            'agent-a',
            self::RUN_ID,
            'p100',
        );

        $encoded = $codec->encode($message);
        $decoded = $codec->decode($encoded, 'agent-a');

        self::assertSame($message->messageId, $decoded->messageId);
        self::assertSame(RemoteControlMessage::ACTION_START, $decoded->action);
        self::assertSame('p100', $decoded->participantId);
        self::assertStringNotContainsString($secret, $encoded);
        self::assertSame(
            [
                'schema_version',
                'namespace',
                'message_id',
                'issued_at_ms',
                'expires_at_ms',
                'action',
                'agent_id',
                'run_id',
                'participant_id',
                'signature',
            ],
            array_keys(json_decode($encoded, true, 8, JSON_THROW_ON_ERROR)),
        );
    }

    public function test_it_issues_random_bounded_messages_from_the_injected_clock(): void
    {
        $clock = new ControlledWorkerControlClock;
        $codec = new RemoteControlMessageCodec($this->config(), $clock);

        $first = $codec->issue(RemoteControlMessage::ACTION_START, 'agent-a', self::RUN_ID, 'p1');
        $second = $codec->issue(RemoteControlMessage::ACTION_STOP, 'agent-a', self::RUN_ID, 'p1');

        self::assertNotSame($first['message']->messageId, $second['message']->messageId);
        self::assertSame($clock->nowMs + 15_000, $first['message']->expiresAtMs);
        self::assertLessThanOrEqual(2_048, strlen($first['envelope']));
        self::assertSame(RemoteControlMessage::ACTION_STOP, $codec->decode(
            $second['envelope'],
            'agent-a',
        )->action);
    }

    /** @return iterable<string, array{Closure(string): string, string}> */
    public static function invalidMessages(): iterable
    {
        yield 'malformed JSON' => [static fn (string $message): string => '{', 'agent-a'];
        yield 'extra field' => [static function (string $message): string {
            $decoded = json_decode($message, true, 8, JSON_THROW_ON_ERROR);
            $decoded['extra'] = true;

            return json_encode($decoded, JSON_THROW_ON_ERROR);
        }, 'agent-a'];
        yield 'changed action' => [static fn (string $message): string => str_replace('"start"', '"stop"', $message), 'agent-a'];
        yield 'changed namespace' => [static fn (string $message): string => str_replace('raceproof:remote', 'raceproof:other', $message), 'agent-a'];
        yield 'changed signature' => [static fn (string $message): string => substr($message, 0, -3).'00"}', 'agent-a'];
        yield 'misrouted agent' => [static fn (string $message): string => $message, 'agent-b'];
    }

    /** @param Closure(string): string $mutate */
    #[DataProvider('invalidMessages')]
    public function test_it_rejects_malformed_tampered_or_misrouted_messages(
        Closure $mutate,
        string $expectedAgent,
    ): void {
        $clock = new ControlledWorkerControlClock;
        $codec = new RemoteControlMessageCodec($this->config(), $clock);
        $issued = $codec->issue(RemoteControlMessage::ACTION_START, 'agent-a', self::RUN_ID, 'p1');

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('control message is invalid');

        $codec->decode($mutate($issued['envelope']), $expectedAgent);
    }

    public function test_it_rejects_expired_future_and_overlong_lifetime_messages(): void
    {
        $clock = new ControlledWorkerControlClock;
        $codec = new RemoteControlMessageCodec($this->config(), $clock);

        foreach (
            [
                new RemoteControlMessage(str_repeat('a', 32), -1, 1, 'start', 'agent-a', self::RUN_ID, 'p1'),
                new RemoteControlMessage(str_repeat('b', 32), $clock->nowMs - 20_000, $clock->nowMs - 1, 'start', 'agent-a', self::RUN_ID, 'p1'),
                new RemoteControlMessage(str_repeat('c', 32), $clock->nowMs + 2_001, $clock->nowMs + 10_000, 'start', 'agent-a', self::RUN_ID, 'p1'),
                new RemoteControlMessage(str_repeat('d', 32), $clock->nowMs, $clock->nowMs + 15_001, 'start', 'agent-a', self::RUN_ID, 'p1'),
            ] as $message
        ) {
            try {
                $codec->decode($codec->encode($message), 'agent-a');
                self::fail('Expected invalid remote message timing to fail.');
            } catch (RaceProofException $exception) {
                self::assertSame(
                    'RaceProof remote worker control message is invalid.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_it_rejects_a_correctly_signed_negative_issue_time(): void
    {
        $secret = self::authenticationSecret();
        $clock = new ControlledWorkerControlClock(0);
        $codec = new RemoteControlMessageCodec($this->config(secret: $secret), $clock);
        $payload = [
            'schema_version' => 1,
            'namespace' => 'raceproof:remote',
            'message_id' => str_repeat('b', 32),
            'issued_at_ms' => -1,
            'expires_at_ms' => 100,
            'action' => 'start',
            'agent_id' => 'agent-a',
            'run_id' => self::RUN_ID,
            'participant_id' => 'p1',
        ];
        $envelope = [
            ...$payload,
            'signature' => hash_hmac(
                'sha256',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $secret,
            ),
        ];

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('control message is invalid');

        $codec->decode(
            json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'agent-a',
        );
    }

    private function config(
        ?string $secret = null,
    ): RemoteWorkerConfiguration {
        return new RemoteWorkerConfiguration(
            'default',
            'raceproof:remote',
            $secret ?? self::authenticationSecret(),
            ['agent-a'],
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
    }

    private static function authenticationSecret(): string
    {
        return str_repeat('raceproof-', 4);
    }
}
