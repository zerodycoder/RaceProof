<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Studio;

/**
 * @internal Studio reads this value from a validated archived report.
 */
final readonly class StudioEvent
{
    /** @param array<string, bool|float|int|string|null> $data */
    public function __construct(
        public string $type,
        public int $occurredAtNs,
        public ?string $participantId,
        public ?string $checkpoint,
        public array $data,
    ) {}
}
