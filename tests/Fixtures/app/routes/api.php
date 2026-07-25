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

Route::post('/participant-spec', function () {
    $token = request()->bearerToken();

    return response()->json([
        'payload' => request()->string('payload')->toString(),
        'header' => request()->header('X-Participant'),
        'cookie' => request()->cookie('participant'),
        'token_hash' => is_string($token) ? hash('sha256', $token) : null,
        'user_id' => auth()->id(),
        'bootstrap' => config('raceproof.fixture.bootstrap'),
    ]);
});
