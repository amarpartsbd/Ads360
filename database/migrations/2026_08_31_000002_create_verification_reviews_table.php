<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The decision history of a verification profile (spec §11).
 *
 * Append-only, like the audit trail: a decision is never edited, a later
 * decision supersedes it. This is what the compliance team reads to see how a
 * case has moved, and it separates the two kinds of note deliberately —
 * `internal_note` is staff-only and never reaches a client-facing response,
 * `client_message` is written to be read by the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_profile_id')->constrained()->cascadeOnDelete();

            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('decision', 32)->index();
            $table->string('from_status', 32);
            $table->string('to_status', 32);

            $table->text('internal_note')->nullable();
            $table->text('client_message')->nullable();

            // Which documents the decision referred to, so "more information
            // needed" points at specific files.
            $table->jsonb('referenced_documents')->default('[]');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['verification_profile_id', 'created_at']);
            $table->index(['reviewer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_reviews');
    }
};
