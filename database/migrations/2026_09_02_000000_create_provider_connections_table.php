<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A client's authorisation for the platform to act on their behalf (spec §16).
 *
 * The token columns hold ciphertext, encrypted by the application before it
 * reaches the database. A database backup, a replica or a stray query result
 * therefore contains nothing that grants access to a client's advertising
 * account. The columns are named `_encrypted` so a plaintext write is obvious
 * in review.
 *
 * Nothing here is ever serialised to a browser (Rule 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32)->index();

            // The provider's identifier for the person who authorised us.
            $table->string('external_user_id', 128);
            $table->string('account_name')->nullable();

            // Ciphertext. Long-lived provider tokens run to several kilobytes.
            // Nullable because a revoked connection keeps its row but must not
            // keep its credentials; the check constraint below holds the line.
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();

            $table->timestamp('expires_at')->nullable();

            // What the provider actually granted, which may be less than what
            // was asked for — discovery reflects the difference.
            $table->jsonb('scopes')->default('[]');

            $table->string('status', 32)->index();
            $table->string('status_detail')->nullable();

            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Consecutive failures, so a flapping connection is distinguishable
            // from one that is genuinely gone.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_error')->nullable();

            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'provider', 'status']);
            $table->index(['status', 'expires_at']);
        });

        /*
         * One live connection per provider account per organization.
         *
         * Reconnecting the same account replaces the old row's status rather
         * than adding a second live grant, so "which token do we use" is never
         * a question with two answers.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_connections_one_live_per_account
            ON provider_connections (organization_id, provider, external_user_id)
            WHERE revoked_at IS NULL
        SQL);

        /*
         * A connection that has not been revoked must still hold a credential.
         * Enforced here rather than only in the disconnect path, so a row can
         * never end up claiming to be usable with nothing behind it.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_connections
            ADD CONSTRAINT provider_connections_live_rows_have_credentials
            CHECK (revoked_at IS NOT NULL OR access_token_encrypted IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
