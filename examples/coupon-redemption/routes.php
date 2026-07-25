<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/coupon/broken', function (): JsonResponse {
    $remaining = (int) DB::table('coupons')->where('id', 1)->value('remaining_uses');

    race_point('coupon-read');

    if ($remaining < 1) {
        return response()->json(['redeemed' => false], 409);
    }

    DB::table('coupons')->where('id', 1)->decrement('remaining_uses');
    DB::table('redemptions')->insert(['coupon_id' => 1]);

    return response()->json(['redeemed' => true], 201);
});

Route::post('/coupon/fixed', function (): JsonResponse {
    race_point('coupon-claim');

    $redeemed = DB::transaction(function (): bool {
        $claimed = DB::table('coupons')
            ->where('id', 1)
            ->where('remaining_uses', '>', 0)
            ->decrement('remaining_uses');

        if ($claimed === 0) {
            return false;
        }

        DB::table('redemptions')->insert(['coupon_id' => 1]);

        return true;
    });

    return response()->json(['redeemed' => $redeemed], $redeemed ? 201 : 409);
});
