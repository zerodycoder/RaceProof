<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExhaustedQueueJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $participantId) {}

    public function handle(): never
    {
        DB::table('queue_attempts')
            ->where('participant_id', $this->participantId)
            ->increment('attempts');

        throw new RuntimeException('Expected exhausted queue job failure.');
    }
}
