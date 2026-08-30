<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants are the outermost isolation boundary of the platform (spec §5).
 *
 * Every business row in the system carries the tenant it belongs to, and no
 * query may cross that boundary. A direct client, an agency and a reseller are
 * all tenants; they differ only in `type` and in the roles their users hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();

            // Externally exposed identifier. Internal auto-increment ids are
            // never used in URLs or API payloads (spec §93).
            $table->ulid('public_id')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 32)->index();
            $table->string('status', 32)->index();

            $table->string('billing_email')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('default_currency', 3)->default('BDT');

            // White-label branding, resolved per tenant so no platform branding
            // is hard-coded in the application (spec §43).
            $table->jsonb('branding')->default('{}');
            $table->jsonb('settings')->default('{}');

            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
