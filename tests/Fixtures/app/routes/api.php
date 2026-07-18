<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RaceProof\Runtime\Checkpoint;

Route::post('/checkpoint', function () {
    race_point('inside-request');

    return response()->json(['released' => true]);
});

Route::post('/bootstrap', function () {
    return response()->json([
        'environment' => getenv('RACEPROOF_BOOTSTRAP_ENV'),
        'configuration' => config('raceproof.fixture.bootstrap'),
        'user_id' => auth()->id(),
        'checkpoint_active' => Checkpoint::active(),
    ]);
});
