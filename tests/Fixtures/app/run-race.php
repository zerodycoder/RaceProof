<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

putenv('APP_ENV=testing');
putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
putenv('RACEPROOF_ENABLED=true');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['RACEPROOF_ENABLED'] = $_SERVER['RACEPROOF_ENABLED'] = 'true';

require dirname(__DIR__, 3).'/vendor/autoload.php';

$database = __DIR__.'/storage/database.sqlite';

if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0700, true);
}

if (! is_file($database)) {
    touch($database);
}

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config()->set('raceproof.runner.cleanup_successful_runs', true);
config()->set('raceproof.runner.spawn_timeout_ms', 10_000);
config()->set('raceproof.runner.run_timeout_ms', 10_000);
config()->set('raceproof.database.reject_in_memory_sqlite', true);

$result = race()
    ->participants(3)
    ->postJson('/api/checkpoint')
    ->releaseWhenAllReach('inside-request')
    ->run();

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
