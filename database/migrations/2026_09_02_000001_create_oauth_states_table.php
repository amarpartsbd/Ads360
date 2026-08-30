<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use OAuth state tokens (spec §16, §98).
 *
 * The `state` parameter is what stops a third party from tricking a signed-in
 * client into completing *their* authorisation — the callback is only accepted
 * if it carries a state this platform issued, to this user, for this
 * organization, and has not already been redeemed.
 *
 * Held server-side rather than in the session so the check does not depend on
 * cookie behaviour across a cross-site redirect, and so redemption is atomic.
 *
 * Only a hash of the state is stored: the value itself travels in the URL, and
 * a leaked table should not let anyone replay one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_states', function (Blueprint $table): void {
            $table->id();

            $table->string('state_hash', 64)->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32);

            // Where to send the client afterwards. Validated against the
            // application's own routes before use, never followed blindly.
            $table->string('redirect_to')->nullable();

            $table->jsonb('scopes')->default('[]');

            $table->timestamp('expires_at');

            // Set the moment it is redeemed, which is what makes it single-use.
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};
