<?php

declare(strict_types=1);

namespace RaceProof\Runtime\Contracts;

interface CheckpointHandler
{
    public function sync(string $name, ?int $timeoutMs = null): void;
}
