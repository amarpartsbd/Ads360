<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising assets a client has authorised the platform to use (spec §15).
 *
 * Discovered from the provider, never entered by hand: a row here means the
 * provider told us this connection may act for this asset. That is what makes
 * it safe to publish in the asset's name (spec §27).
 *
 * Core fields are columns; anything provider-specific goes in `metadata`, per
 * spec §22 — the application model is not dumped into JSON, but neither is the
 * schema reshaped every time a provider adds a field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_assets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32)->index();
            $table->string('type', 48)->index();

            $table->string('external_id', 128);
            $table->string('name');

            $table->string('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();

            // What the provider says about the asset, verbatim.
            $table->string('provider_status', 48)->nullable();

            // Whether the platform can currently use it: an asset can be fine
            // at the provider but unusable here because the grant behind it
            // lapsed.
            $table->string('status', 32)->index();

            $table->jsonb('metadata')->default('{}');

            // Set when the provider stops listing it, rather than deleting the
            // row — a campaign may still reference it historically.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('unavailable_since')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'type', 'status']);
            $table->index(['provider_connection_id', 'type']);
        });

        /*
         * One row per asset per connection. Re-running discovery updates what
         * is there rather than accumulating duplicates of the same page.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_assets_unique_per_connection
            ON provider_assets (provider_connection_id, type, external_id)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_assets');
    }
};
