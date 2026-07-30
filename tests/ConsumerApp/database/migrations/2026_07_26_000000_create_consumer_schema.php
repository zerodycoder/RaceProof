<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('api_token')->nullable()->unique();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('redeemed_by')->nullable();
            $table->timestamps();
        });

        Schema::connection('queue_sqlite')->dropIfExists('jobs');
        Schema::connection('queue_sqlite')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('queued_coupon_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->string('participant_id')->unique();
            $table->boolean('claimed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_coupon_outcomes');
        Schema::connection('queue_sqlite')->dropIfExists('jobs');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
