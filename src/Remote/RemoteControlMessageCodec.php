<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use JsonException;
use RaceProof\Laravel\Contracts\WorkerControlClock;
use RaceProof\Laravel\Exceptions\RaceProofException;

/**
 * @internal
 */
final readonly class RemoteControlMessageCodec
{
    private const SCHEMA_VERSION = 1;

    public function __construct(
        private RemoteWorkerConfiguration $config,
        private WorkerControlClock $clock,
    ) {}

    /** @return array{message: RemoteControlMessage, envelope: string} */
    public function issue(
        string $action,
        string $agentId,
        string $runId,
        string $participantId,
    ): array {
        $now = $this->clock->nowMilliseconds();
        $message = new RemoteControlMessage(
            bin2hex(random_bytes(16)),
            $now,
            $now + $this->config->messageTtlMs,
            $action,
            $agentId,
            $runId,
            $participantId,
        );

        return ['message' => $message, 'envelope' => $this->encode($message)];
    }

    public function encode(RemoteControlMessage $message): string
    {
        if (
            preg_match('/^[a-f0-9]{32}$/D', $message->messageId) !== 1
            || $message->issuedAtMs < 0
            || $message->expiresAtMs <= $message->issuedAtMs
            || $message->expiresAtMs - $message->issuedAtMs > $this->config->messageTtlMs
            || ! in_array($message->action, [RemoteControlMessage::ACTION_START, RemoteControlMessage::ACTION_STOP], true)
            || ! in_array($message->agentId, $this->config->agents, true)
            || preg_match('/^[a-f0-9]{32}$/D', $message->runId) !== 1
            || preg_match('/^p(?:[1-9]|[1-9][0-9]|100)$/D', $message->participantId) !== 1
        ) {
            throw new RaceProofException('RaceProof remote worker control message is invalid.');
        }

        $payload = $this->payload($message);
        $envelope = [...$payload, 'signature' => hash_hmac(
            'sha256',
            $this->canonical($payload),
            $this->config->secret,
        )];
        $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($encoded) > $this->config->controlMessageBytes) {
            throw new RaceProofException('RaceProof remote worker control message exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded, string $expectedAgent): RemoteControlMessage
    {
        try {
            if ($encoded === '' || strlen($encoded) > $this->config->controlMessageBytes) {
                throw new JsonException;
            }

            $payload = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || array_is_list($payload) || array_keys($payload) !== [
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
            ]) {
                throw new JsonException;
            }

            $signature = $payload['signature'];
            unset($payload['signature']);

            if (
                $payload['schema_version'] !== self::SCHEMA_VERSION
                || ! is_string($payload['namespace'])
                || $payload['namespace'] !== $this->config->namespace
                || ! is_string($payload['message_id'])
                || preg_match('/^[a-f0-9]{32}$/D', $payload['message_id']) !== 1
                || ! is_int($payload['issued_at_ms'])
                || ! is_int($payload['expires_at_ms'])
                || ! is_string($payload['action'])
                || ! in_array($payload['action'], [RemoteControlMessage::ACTION_START, RemoteControlMessage::ACTION_STOP], true)
                || ! is_string($payload['agent_id'])
                || $payload['agent_id'] !== $expectedAgent
                || ! is_string($payload['run_id'])
                || preg_match('/^[a-f0-9]{32}$/D', $payload['run_id']) !== 1
                || ! is_string($payload['participant_id'])
                || preg_match('/^p(?:[1-9]|[1-9][0-9]|100)$/D', $payload['participant_id']) !== 1
                || ! is_string($signature)
                || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            ) {
                throw new JsonException;
            }

            $now = $this->clock->nowMilliseconds();
            $canonicalPayload = [
                'schema_version' => $payload['schema_version'],
                'namespace' => $payload['namespace'],
                'message_id' => $payload['message_id'],
                'issued_at_ms' => $payload['issued_at_ms'],
                'expires_at_ms' => $payload['expires_at_ms'],
                'action' => $payload['action'],
                'agent_id' => $payload['agent_id'],
                'run_id' => $payload['run_id'],
                'participant_id' => $payload['participant_id'],
            ];

            if (
                $payload['issued_at_ms'] < 0
                || $payload['issued_at_ms'] > $now + $this->config->maxClockSkewMs
                || $payload['expires_at_ms'] <= $now
                || $payload['expires_at_ms'] <= $payload['issued_at_ms']
                || $this->config->messageTtlMs < $payload['expires_at_ms'] - $payload['issued_at_ms']
                || ! hash_equals(
                    hash_hmac('sha256', $this->canonical($canonicalPayload), $this->config->secret),
                    $signature,
                )
            ) {
                throw new JsonException;
            }

            return new RemoteControlMessage(
                $payload['message_id'],
                $payload['issued_at_ms'],
                $payload['expires_at_ms'],
                $payload['action'],
                $payload['agent_id'],
                $payload['run_id'],
                $payload['participant_id'],
            );
        } catch (\Throwable) {
            throw new RaceProofException('RaceProof remote worker control message is invalid.');
        }
    }

    /**
     * @return array{
     *     schema_version: int,
     *     namespace: string,
     *     message_id: string,
     *     issued_at_ms: int,
     *     expires_at_ms: int,
     *     action: string,
     *     agent_id: string,
     *     run_id: string,
     *     participant_id: string
     * }
     */
    private function payload(RemoteControlMessage $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'namespace' => $this->config->namespace,
            'message_id' => $message->messageId,
            'issued_at_ms' => $message->issuedAtMs,
            'expires_at_ms' => $message->expiresAtMs,
            'action' => $message->action,
            'agent_id' => $message->agentId,
            'run_id' => $message->runId,
            'participant_id' => $message->participantId,
        ];
    }

    /** @param array<string, int|string> $payload */
    private function canonical(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
