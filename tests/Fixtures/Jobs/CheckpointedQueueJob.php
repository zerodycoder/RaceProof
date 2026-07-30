<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class CheckpointedQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $participantId) {}

    public function handle(): void
    {
        race_point('inside-queue-job');

        DB::table('queue_results')->insert([
            'participant_id' => $this->participantId,
            'kind' => 'checkpoint',
        ]);
    }
}
