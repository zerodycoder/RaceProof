<?php

declare(strict_types=1);

use RaceProof\Laravel\RaceBuilder;

if (! function_exists('race')) {
    function race(): RaceBuilder
    {
        return app(RaceBuilder::class);
    }
}
