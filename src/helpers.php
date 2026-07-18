<?php

declare(strict_types=1);

use RaceProof\Laravel\RaceBuilder;
use RaceProof\Laravel\RacePoint;

if (! function_exists('race')) {
    function race(): RaceBuilder
    {
        return app(RaceBuilder::class);
    }
}

if (! function_exists('race_point')) {
    function race_point(string $name, ?int $timeoutMs = null): void
    {
        app(RacePoint::class)->sync($name, $timeoutMs);
    }
}
