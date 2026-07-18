<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/** @method static void sync(string $name, ?int $timeoutMs = null) */
final class RacePoint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \RaceProof\Laravel\RacePoint::class;
    }
}
