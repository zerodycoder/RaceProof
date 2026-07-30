<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RaceProof\Laravel\Tests\Fixtures\Jobs\CheckpointedQueueJob;
use RaceProof\Laravel\Tests\Fixtures\Jobs\ExhaustedQueueJob;
use RaceProof\Laravel\Tests\Fixtures\Jobs\RetryOnceQueueJob;

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'QUEUE_CONNECTION' => 'raceproof_database',
    'RACEPROOF_ENABLED' => 'true',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require dirname(__DIR__, 3).'/vendor/autoload.php';

$database = __DIR__.'/storage/database.sqlite';
$queueDatabase = __DIR__.'/storage/queue.sqlite';

if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0700, true);
}

foreach ([$database, $queueDatabase] as $databaseFile) {
    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }

    touch($databaseFile);
}

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Schema::connection('queue_sqlite')->create('jobs', function (Blueprint $table): void {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});
Schema::create('queue_results', function (Blueprint $table): void {
    $table->id();
    $table->string('participant_id');
    $table->string('kind');
});
Schema::create('queue_attempts', function (Blueprint $table): void {
    $table->string('participant_id')->primary();
    $table->unsignedInteger('attempts')->default(0);
});

config()->set('raceproof.runner.cleanup_successful_runs', true);
config()->set('raceproof.runner.spawn_timeout_ms', 10_000);
config()->set('raceproof.runner.run_timeout_ms', 15_000);
config()->set('raceproof.runner.poll_interval_ms', 5);
config()->set('raceproof.database.reject_in_memory_sqlite', true);

$checkpointed = race()
    ->participants(3)
    ->queue(
        static fn (string $participantId): CheckpointedQueueJob => new CheckpointedQueueJob($participantId),
        'raceproof_database',
    )
    ->releaseWhenAllReach('inside-queue-job')
    ->run();

foreach (['p1', 'p2'] as $participantId) {
    DB::table('queue_attempts')->insert([
        'participant_id' => $participantId,
        'attempts' => 0,
    ]);
}

$retried = race()
    ->participants(2)
    ->queue(
        static fn (string $participantId): RetryOnceQueueJob => new RetryOnceQueueJob($participantId),
        'raceproof_database',
    )
    ->queueAttempts(2)
    ->run();

DB::table('queue_attempts')->update(['attempts' => 0]);

$exhausted = race()
    ->participants(2)
    ->queue(
        static fn (string $participantId): ExhaustedQueueJob => new ExhaustedQueueJob($participantId),
        'raceproof_database',
    )
    ->queueAttempts(2)
    ->run();

echo json_encode([
    'checkpointed' => $checkpointed,
    'retried' => $retried,
    'exhausted' => $exhausted,
    'rows' => [
        'checkpointed' => DB::table('queue_results')->where('kind', 'checkpoint')->count(),
        'retried' => DB::table('queue_results')->where('kind', 'retried')->count(),
        'remaining_jobs' => DB::connection('queue_sqlite')->table('jobs')->count(),
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
