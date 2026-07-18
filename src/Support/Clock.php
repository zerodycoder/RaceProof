<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Exceptions\RaceProofException;

final class Clock
{
    public static function nowNs(): int
    {
        $value = hrtime(true);

        if (! is_int($value)) {
            throw new RaceProofException('RaceProof requires a 64-bit monotonic clock.');
        }

        return $value;
    }
}
