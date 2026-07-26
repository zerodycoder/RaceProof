<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth-probe', function (Request $request) {
    return response()->json([
        'user_id' => $request->user()?->getAuthIdentifier(),
    ]);
})
    ->middleware('auth:web,token,sanctum')
    ->withoutMiddleware(ValidateCsrfToken::class);
