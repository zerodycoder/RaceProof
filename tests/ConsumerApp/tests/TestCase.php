<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            storage_path('framework/raceproof'),
            storage_path('framework/raceproof-studio'),
            storage_path('framework/consumer-generated'),
            storage_path('framework/sessions'),
        ] as $directory) {
            File::deleteDirectory($directory);
            File::ensureDirectoryExists($directory);
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }
}
