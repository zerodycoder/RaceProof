<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

if (is_file($database)) {
    unlink($database);
}

touch($database);

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config()->set('raceproof.runner.cleanup_successful_runs', true);
config()->set('raceproof.runner.spawn_timeout_ms', 10_000);
config()->set('raceproof.runner.run_timeout_ms', 10_000);

Schema::create('products', function (Blueprint $table): void {
    $table->id();
    $table->integer('stock');
});
Schema::create('orders', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('product_id');
});

$reset = static function (): void {
    DB::table('orders')->delete();
    DB::table('products')->delete();
    DB::table('products')->insert(['id' => 1, 'stock' => 1]);
};

$reset();

$broken = race()
    ->participants(3)
    ->postJson('/api/broken')
    ->releaseWhenAllReach('stock-read')
    ->run();

$brokenState = [
    'result' => $broken,
    'stock' => (int) DB::table('products')->where('id', 1)->value('stock'),
    'orders' => DB::table('orders')->count(),
];

$reset();

$fixed = race()
    ->participants(3)
    ->postJson('/api/fixed')
    ->releaseWhenAllReach('before-atomic-update')
    ->run();

$fixedState = [
    'result' => $fixed,
    'stock' => (int) DB::table('products')->where('id', 1)->value('stock'),
    'orders' => DB::table('orders')->count(),
];

echo json_encode([
    'broken' => $brokenState,
    'fixed' => $fixedState,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
