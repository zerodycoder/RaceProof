<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/oversell/broken', function (): JsonResponse {
    $stock = (int) DB::table('products')->where('id', 1)->value('stock');

    race_point('oversell-read');

    if ($stock < 1) {
        return response()->json(['created' => false], 409);
    }

    DB::table('products')->where('id', 1)->decrement('stock');
    DB::table('orders')->insert(['product_id' => 1]);

    return response()->json(['created' => true], 201);
});

Route::post('/oversell/fixed', function (): JsonResponse {
    race_point('oversell-claim');

    $created = DB::transaction(function (): bool {
        $claimed = DB::table('products')
            ->where('id', 1)
            ->where('stock', '>', 0)
            ->decrement('stock');

        if ($claimed === 0) {
            return false;
        }

        DB::table('orders')->insert(['product_id' => 1]);

        return true;
    });

    return response()->json(['created' => $created], $created ? 201 : 409);
});
