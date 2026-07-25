<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class FixtureUser extends Authenticatable
{
    use HasApiTokens;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
