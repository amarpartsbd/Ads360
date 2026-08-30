<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identities.
 *
 * A user belongs to at most one tenant. Platform staff have `tenant_id = null`
 * and are marked `is_platform_user`. Keeping a user inside a single tenant lets
 * login stay a plain email lookup while making cross-tenant membership
 * impossible at the database level rather than only in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_platform_user')->default(false)->index();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('mobile_number', 32)->nullable();

            // Argon2id (spec §8). Never anything reversible.
            $table->string('password');

            $table->string('status', 32)->index();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 8)->default('en');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Brute-force protection (spec §8).
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamp('terms_accepted_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Sessions live in the database so a user can review and revoke their
        // own active sessions from the security settings page (spec §8, §14).
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
