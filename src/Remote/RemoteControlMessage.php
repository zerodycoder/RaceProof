<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

/**
 * @internal
 */
final readonly class RemoteControlMessage
{
    public const ACTION_START = 'start';

    public const ACTION_STOP = 'stop';

    public function __construct(
        public string $messageId,
        public int $issuedAtMs,
        public int $expiresAtMs,
        public string $action,
        public string $agentId,
        public string $runId,
        public string $participantId,
    ) {}
}
