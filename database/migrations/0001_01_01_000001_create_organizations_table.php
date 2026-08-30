<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization is the advertiser account users actually work inside
 * (spec §5). A direct client tenant holds one; an agency tenant holds one per
 * client it manages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('legal_name')->nullable();
            $table->string('business_type', 64)->nullable();

            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('default_currency', 3)->default('BDT');

            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_number', 32)->nullable();

            $table->string('status', 32)->index();
            $table->jsonb('settings')->default('{}');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Slugs only need to be unique inside their tenant.
            $table->unique(['tenant_id', 'slug']);

            // Matches the dominant access pattern: "this tenant's organizations
            // in a given state" (spec §58).
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
