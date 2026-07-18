<?php

declare(strict_types=1);

namespace RaceProof\Runtime;

/** @internal Issued by Checkpoint::activate() inside a trusted worker process. */
final readonly class CheckpointActivation
{
    public function __construct(public string $id) {}
}
