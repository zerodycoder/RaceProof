<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RaceProof\Laravel\RaceProofServiceProvider;
use RaceProof\Runtime\Checkpoint;
use Tests\TestCase;

final class PublishedUpgradeSmokeTest extends TestCase
{
    public function test_runtime_discovery_and_race_invariant_survive_the_upgrade(): void
    {
        self::assertTrue($this->app->providerIsLoaded(RaceProofServiceProvider::class));
        self::assertTrue(function_exists('race_point'));
        self::assertFalse(Checkpoint::active());

        DB::table('coupons')->insert([
            'id' => 1,
            'code' => 'UPGRADE-ONCE',
            'redeemed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = race()
            ->participants(3)
            ->postJson('/api/coupons/1/redeem', ['user_id' => 42])
            ->releaseWhenAllReach('coupon-claim')
            ->run();

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoTimeouts()
            ->assertNoServerErrors()
            ->assertStatusCount(201, 1)
            ->assertStatusCount(409, 2)
            ->assertInvariant(
                fn (): bool => DB::table('coupons')->where('id', 1)->value('redeemed_by') === 42,
                'Exactly one participant must redeem the coupon after package installation.',
            );
    }
}
