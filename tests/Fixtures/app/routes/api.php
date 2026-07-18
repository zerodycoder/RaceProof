<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post('/checkpoint', function () {
    race_point('inside-request');

    return response()->json(['released' => true]);
});
