<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class RedeemQueuedCoupon implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $couponId,
        public readonly int $userId,
        public readonly string $participantId,
    ) {}

    public function handle(): void
    {
        race_point('queued-coupon-claim');

        retry(50, function (): void {
            DB::transaction(function (): void {
                $claimed = DB::table('coupons')
                    ->where('id', $this->couponId)
                    ->whereNull('redeemed_by')
                    ->update([
                        'redeemed_by' => $this->userId,
                        'updated_at' => now(),
                    ]);

                DB::table('queued_coupon_outcomes')->insert([
                    'participant_id' => $this->participantId,
                    'claimed' => $claimed === 1,
                ]);
            });
        }, 10);
    }
}
