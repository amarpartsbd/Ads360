<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending invitations to join an organization (spec §82).
 *
 * Only a hash of the invitation token is stored. The token itself exists once,
 * in the email that was sent; a leaked database row cannot be used to accept an
 * invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('name')->nullable();

            // The role the invitee receives on acceptance. Validated against
            // what the inviter may grant at the time the invitation is created,
            // and again when it is accepted.
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();

            $table->string('status', 32)->index();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['email', 'status']);
        });

        // One live invitation per address per organization. Superseded rows are
        // no longer PENDING, so re-inviting after a revoke or expiry is fine.
        Illuminate\Support\Facades\DB::statement(
            "CREATE UNIQUE INDEX organization_invitations_pending_unique
             ON organization_invitations (organization_id, lower(email))
             WHERE status = 'PENDING'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
