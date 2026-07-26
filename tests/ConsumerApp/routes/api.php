<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/coupons/{coupon}/redeem', function (int $coupon, Request $request) {
    race_point('coupon-claim');

    $redeemed = DB::table('coupons')
        ->where('id', $coupon)
        ->whereNull('redeemed_by')
        ->update([
            'redeemed_by' => (int) $request->integer('user_id'),
            'updated_at' => now(),
        ]);

    return response()->json(
        ['redeemed' => $redeemed === 1],
        $redeemed === 1 ? 201 : 409,
    );
});

Route::post('/participant-context', function (Request $request) {
    $token = $request->bearerToken();

    return response()->json([
        'payload' => $request->string('payload')->toString(),
        'header' => $request->header('X-Participant'),
        'cookie' => $request->cookie('participant'),
        'token_hash' => is_string($token) ? hash('sha256', $token) : null,
        'user_id' => $request->user()?->getAuthIdentifier(),
        'bootstrap' => config('consumer.participant'),
    ]);
});
