<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

final class RunId
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
