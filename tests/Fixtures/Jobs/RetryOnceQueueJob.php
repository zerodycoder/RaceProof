<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RetryOnceQueueJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $participantId) {}

    public function handle(): void
    {
        $attempts = DB::table('queue_attempts')
            ->where('participant_id', $this->participantId)
            ->increment('attempts');

        if ($attempts !== 1) {
            throw new RuntimeException('Queue attempt state was unavailable.');
        }

        $attempt = (int) DB::table('queue_attempts')
            ->where('participant_id', $this->participantId)
            ->value('attempts');

        if ($attempt === 1) {
            throw new RuntimeException('Expected first queue attempt failure.');
        }

        DB::table('queue_results')->insert([
            'participant_id' => $this->participantId,
            'kind' => 'retried',
        ]);
    }
}
