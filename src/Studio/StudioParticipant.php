<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Studio;

/**
 * @internal Studio reads this value from a validated archived report.
 */
final readonly class StudioParticipant
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $id,
        public string $outcome,
        public ?int $status,
        public float $durationMs,
        public string $diagnostic,
        public string $body,
        public bool $bodyTruncated,
        public array $headers,
        public bool $headersTruncated,
        public ?string $exceptionClass,
    ) {}
}
