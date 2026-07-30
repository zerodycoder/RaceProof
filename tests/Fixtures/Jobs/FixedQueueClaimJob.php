<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class FixedQueueClaimJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $claimKey) {}

    public function handle(): void
    {
        race_point('queue-claim-insert');

        DB::table('queue_claims_fixed')->insertOrIgnore([
            'claim_key' => $this->claimKey,
        ]);
    }
}
