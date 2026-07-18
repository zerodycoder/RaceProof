<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/broken', function () {
    $product = DB::table('products')->where('id', 1)->first();

    race_point('stock-read');

    if ($product === null || $product->stock < 1) {
        return response()->json(['message' => 'Out of stock'], 409);
    }

    DB::table('products')->where('id', 1)->decrement('stock');
    DB::table('orders')->insert(['product_id' => 1]);

    return response()->json(['created' => true], 201);
});

Route::post('/fixed', function () {
    race_point('before-atomic-update');

    $claimed = DB::table('products')
        ->where('id', 1)
        ->where('stock', '>', 0)
        ->decrement('stock');

    if ($claimed === 0) {
        return response()->json(['message' => 'Out of stock'], 409);
    }

    DB::table('orders')->insert(['product_id' => 1]);

    return response()->json(['created' => true], 201);
});
