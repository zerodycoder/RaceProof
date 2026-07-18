<?php

declare(strict_types=1);

use RaceProof\Runtime\Checkpoint;

if (! function_exists('race_point')) {
    function race_point(string $name, ?int $timeoutMs = null): void
    {
        Checkpoint::sync($name, $timeoutMs);
    }
}
