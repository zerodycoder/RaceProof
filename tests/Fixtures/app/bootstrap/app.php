<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\SanctumServiceProvider;
use RaceProof\Laravel\RaceProofServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )
    ->withProviders([
        SanctumServiceProvider::class,
        RaceProofServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The fixture intentionally uses the standard API middleware stack.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Worker exceptions are captured by RaceProof.
    })
    ->create();
