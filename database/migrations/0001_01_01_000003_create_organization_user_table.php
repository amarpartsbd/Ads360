<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization membership.
 *
 * This table is the single trusted source of tenant context: the tenant a
 * request operates in is derived from the authenticated user's membership, never
 * from anything the client sends (spec §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalised so membership can be filtered by tenant without a
            // join, and so the tenant of a membership is verifiable in one row.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32)->index();
            $table->boolean('is_primary')->default(false);

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
