<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->integer('stock');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->integer('remaining_uses');
        });

        Schema::create('redemptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
        });

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->integer('balance');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->integer('amount');
        });

        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });

        Schema::create('acceptances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
        });

        Schema::create('claims_broken', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_key');
        });

        Schema::create('claims_fixed', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_key')->unique();
        });

        Schema::create('lock_counters', function (Blueprint $table): void {
            $table->id();
            $table->integer('version');
        });

        Schema::create('deadlock_rows', function (Blueprint $table): void {
            $table->id();
            $table->integer('value');
        });

        Schema::create('timeout_rows', function (Blueprint $table): void {
            $table->id();
            $table->integer('value');
        });

        Schema::create('exchange_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('participant_id')->unique();
            $table->integer('base_balance');
            $table->integer('quote_balance');
        });

        Schema::create('exchange_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
            $table->string('side');
            $table->integer('price');
            $table->integer('original_quantity');
            $table->integer('remaining_quantity');
            $table->string('status');
        });

        Schema::create('exchange_fills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('participant_id');
            $table->integer('quantity');
            $table->integer('price');
            $table->integer('quote_amount');
            $table->unique(['order_id', 'participant_id']);
        });

        Schema::create('exchange_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');
            $table->string('participant_id');
            $table->string('asset');
            $table->integer('amount');
            $table->unique(['reference', 'participant_id', 'asset']);
        });

        Schema::create('scenario_metrics', function (Blueprint $table): void {
            $table->string('metric')->primary();
            $table->integer('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_metrics');
        Schema::dropIfExists('exchange_ledger_entries');
        Schema::dropIfExists('exchange_fills');
        Schema::dropIfExists('exchange_orders');
        Schema::dropIfExists('exchange_accounts');
        Schema::dropIfExists('timeout_rows');
        Schema::dropIfExists('deadlock_rows');
        Schema::dropIfExists('lock_counters');
        Schema::dropIfExists('claims_fixed');
        Schema::dropIfExists('claims_broken');
        Schema::dropIfExists('acceptances');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};
