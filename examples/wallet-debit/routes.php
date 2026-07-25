<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/wallet/broken', function (): JsonResponse {
    $balance = (int) DB::table('wallets')->where('id', 1)->value('balance');

    race_point('wallet-read');

    if ($balance < 80) {
        return response()->json(['debited' => false], 409);
    }

    DB::table('wallets')->where('id', 1)->update(['balance' => $balance - 80]);
    DB::table('ledger_entries')->insert(['wallet_id' => 1, 'amount' => 80]);

    return response()->json(['debited' => true], 201);
});

Route::post('/wallet/fixed', function (): JsonResponse {
    race_point('wallet-claim');

    $debited = DB::transaction(function (): bool {
        $claimed = DB::table('wallets')
            ->where('id', 1)
            ->where('balance', '>=', 80)
            ->decrement('balance', 80);

        if ($claimed === 0) {
            return false;
        }

        DB::table('ledger_entries')->insert(['wallet_id' => 1, 'amount' => 80]);

        return true;
    });

    return response()->json(['debited' => $debited], $debited ? 201 : 409);
});
