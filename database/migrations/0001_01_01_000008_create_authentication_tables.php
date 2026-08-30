<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login history and trusted devices (spec §8).
 *
 * Login attempts are recorded whether or not they succeed, and whether or not
 * the email matches a real account, so credential-stuffing shows up in the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Recorded even when no user matches, so failures against unknown
            // addresses are still visible. Never stores the attempted password.
            $table->string('email')->index();

            $table->boolean('successful')->index();
            $table->string('failure_reason', 64)->nullable();
            $table->boolean('two_factor_used')->default(false);

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->string('country', 2)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('fingerprint', 64);
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();

            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('trust_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip_address', 45)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('login_histories');
    }
};
