<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use RaceProof\Laravel\RaceProofServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php')
    ->withProviders([RaceProofServiceProvider::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // Use Laravel's normal API middleware stack.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Database conflicts are translated by the evidence routes.
    })
    ->create();
