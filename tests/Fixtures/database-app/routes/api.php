<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RaceProof\Laravel\Execution\RaceContext;

function raceproofEvidenceParticipant(): string
{
    return (string) app(RaceContext::class)->participantId();
}

function raceproofEvidenceConflict(QueryException $exception): JsonResponse
{
    return response()->json([
        'conflict' => true,
        'sql_state' => $exception->errorInfo[0] ?? null,
    ], 503);
}

require dirname(__DIR__, 4).'/examples/overselling/routes.php';
require dirname(__DIR__, 4).'/examples/coupon-redemption/routes.php';
require dirname(__DIR__, 4).'/examples/wallet-debit/routes.php';
require dirname(__DIR__, 4).'/examples/quote-acceptance/routes.php';

Route::post('/unique/broken', function (): JsonResponse {
    $exists = DB::table('claims_broken')->where('claim_key', 'alpha')->exists();

    race_point('unique-check');

    if ($exists) {
        return response()->json(['claimed' => false], 409);
    }

    DB::table('claims_broken')->insert(['claim_key' => 'alpha']);

    return response()->json(['claimed' => true], 201);
});

Route::post('/unique/fixed', function (): JsonResponse {
    race_point('unique-insert');

    $inserted = DB::table('claims_fixed')->insertOrIgnore(['claim_key' => 'alpha']);

    return response()->json(['claimed' => $inserted === 1], $inserted === 1 ? 201 : 409);
});

Route::post('/lock/broken', function (): JsonResponse {
    $counter = DB::table('lock_counters')->where('id', 1)->lockForUpdate()->first();

    race_point('lock-read');

    DB::table('lock_counters')->where('id', 1)->update(['version' => (int) $counter->version + 1]);

    return response()->json(['updated' => true], 201);
});

Route::post('/lock/fixed', function (): JsonResponse {
    race_point('before-lock');

    DB::transaction(function (): void {
        $counter = DB::table('lock_counters')->where('id', 1)->lockForUpdate()->first();
        DB::table('lock_counters')->where('id', 1)->update(['version' => (int) $counter->version + 1]);
    });

    return response()->json(['updated' => true], 201);
});

Route::post('/deadlock/broken', function (): JsonResponse {
    $first = raceproofEvidenceParticipant() === 'p1' ? 1 : 2;
    $second = $first === 1 ? 2 : 1;

    try {
        DB::transaction(function () use ($first, $second): void {
            DB::table('deadlock_rows')->where('id', $first)->lockForUpdate()->first();

            race_point('deadlock-first-lock');

            DB::table('deadlock_rows')->where('id', $second)->lockForUpdate()->first();
            DB::table('scenario_metrics')->where('metric', 'deadlock_completed')->increment('value');
        });
    } catch (QueryException $exception) {
        return raceproofEvidenceConflict($exception);
    }

    return response()->json(['completed' => true], 201);
});

Route::post('/deadlock/fixed', function (): JsonResponse {
    race_point('before-ordered-locks');

    DB::transaction(function (): void {
        DB::table('deadlock_rows')->whereIn('id', [1, 2])->orderBy('id')->lockForUpdate()->get();
        DB::table('scenario_metrics')->where('metric', 'deadlock_completed')->increment('value');
    });

    return response()->json(['completed' => true], 201);
});

Route::post('/timeout/{mode}', function (string $mode): JsonResponse {
    $broken = $mode === 'broken';

    if (raceproofEvidenceParticipant() === 'p1') {
        DB::transaction(function () use ($broken): void {
            DB::table('timeout_rows')->where('id', 1)->lockForUpdate()->first();

            race_point('timeout-lock-held');

            usleep($broken ? 1_800_000 : 100_000);
            DB::table('scenario_metrics')->where('metric', 'timeout_completed')->increment('value');
        });

        return response()->json(['completed' => true], 201);
    }

    race_point('timeout-lock-held');

    try {
        DB::transaction(function () use ($broken): void {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'pgsql') {
                $timeout = $broken ? '250ms' : '3s';
                DB::statement("SET LOCAL lock_timeout = '{$timeout}'");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = '.($broken ? '1' : '3'));
            }

            DB::table('timeout_rows')->where('id', 1)->lockForUpdate()->first();
            DB::table('scenario_metrics')->where('metric', 'timeout_completed')->increment('value');
        });
    } catch (QueryException $exception) {
        return raceproofEvidenceConflict($exception);
    }

    return response()->json(['completed' => true], 201);
})->whereIn('mode', ['broken', 'fixed']);

Route::post('/critical/broken', function (): JsonResponse {
    $stock = (int) DB::table('products')->where('id', 1)->value('stock');
    $couponUses = (int) DB::table('coupons')->where('id', 1)->value('remaining_uses');
    $balance = (int) DB::table('wallets')->where('id', 1)->value('balance');
    $quoteStatus = (string) DB::table('quotes')->where('id', 1)->value('status');
    $claimExists = DB::table('claims_broken')->where('claim_key', 'alpha')->exists();
    $counter = DB::table('lock_counters')->where('id', 1)->lockForUpdate()->first();

    race_point('critical-read');

    if ($stock > 0) {
        DB::table('products')->where('id', 1)->decrement('stock');
        DB::table('orders')->insert(['product_id' => 1]);
    }

    if ($couponUses > 0) {
        DB::table('coupons')->where('id', 1)->decrement('remaining_uses');
        DB::table('redemptions')->insert(['coupon_id' => 1]);
    }

    if ($balance >= 80) {
        DB::table('wallets')->where('id', 1)->update(['balance' => $balance - 80]);
        DB::table('ledger_entries')->insert(['wallet_id' => 1, 'amount' => 80]);
    }

    if ($quoteStatus === 'pending') {
        DB::table('quotes')->where('id', 1)->update(['status' => 'accepted']);
        DB::table('acceptances')->insert(['quote_id' => 1]);
    }

    if (! $claimExists) {
        DB::table('claims_broken')->insert(['claim_key' => 'alpha']);
    }

    DB::table('lock_counters')->where('id', 1)->update(['version' => (int) $counter->version + 1]);

    return response()->json(['completed' => true], 201);
});

Route::post('/critical/fixed', function (): JsonResponse {
    race_point('critical-claim');

    if (DB::table('products')->where('id', 1)->where('stock', '>', 0)->decrement('stock') === 1) {
        DB::table('orders')->insert(['product_id' => 1]);
    }

    if (DB::table('coupons')->where('id', 1)->where('remaining_uses', '>', 0)->decrement('remaining_uses') === 1) {
        DB::table('redemptions')->insert(['coupon_id' => 1]);
    }

    if (DB::table('wallets')->where('id', 1)->where('balance', '>=', 80)->decrement('balance', 80) === 1) {
        DB::table('ledger_entries')->insert(['wallet_id' => 1, 'amount' => 80]);
    }

    if (DB::table('quotes')->where('id', 1)->where('status', 'pending')->update(['status' => 'accepted']) === 1) {
        DB::table('acceptances')->insert(['quote_id' => 1]);
    }

    DB::table('claims_fixed')->insertOrIgnore(['claim_key' => 'alpha']);

    DB::transaction(function (): void {
        $counter = DB::table('lock_counters')->where('id', 1)->lockForUpdate()->first();
        DB::table('lock_counters')->where('id', 1)->update(['version' => (int) $counter->version + 1]);
    });

    return response()->json(['completed' => true], 201);
});
