<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/quote/broken', function (): JsonResponse {
    $status = (string) DB::table('quotes')->where('id', 1)->value('status');

    race_point('quote-read');

    if ($status !== 'pending') {
        return response()->json(['accepted' => false], 409);
    }

    DB::table('quotes')->where('id', 1)->update(['status' => 'accepted']);
    DB::table('acceptances')->insert(['quote_id' => 1]);

    return response()->json(['accepted' => true], 201);
});

Route::post('/quote/fixed', function (): JsonResponse {
    race_point('quote-claim');

    $accepted = DB::transaction(function (): bool {
        $claimed = DB::table('quotes')
            ->where('id', 1)
            ->where('status', 'pending')
            ->update(['status' => 'accepted']);

        if ($claimed === 0) {
            return false;
        }

        DB::table('acceptances')->insert(['quote_id' => 1]);

        return true;
    });

    return response()->json(['accepted' => $accepted], $accepted ? 201 : 409);
});
