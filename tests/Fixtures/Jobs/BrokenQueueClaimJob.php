<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class BrokenQueueClaimJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $claimKey) {}

    public function handle(): void
    {
        $exists = DB::table('queue_claims_broken')
            ->where('claim_key', $this->claimKey)
            ->exists();

        race_point('queue-claim-read');

        if (! $exists) {
            DB::table('queue_claims_broken')->insert(['claim_key' => $this->claimKey]);
        }
    }
}
