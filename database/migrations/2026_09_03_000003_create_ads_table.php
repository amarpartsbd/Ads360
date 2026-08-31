<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Individual ads (spec §21, §23).
 *
 * An ad carries the copy and points at a creative and at the identity it runs
 * under — the client's own connected page or account, never one of ours. The
 * identity is a ProviderAsset the client authorised, which is why the ad
 * references that table rather than storing a page id as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_set_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('status', 32)->index();

            // Restricted rather than cascaded: deleting a creative that a
            // published ad is running would break the ad at the provider.
            $table->foreignId('creative_id')->nullable()->constrained()->restrictOnDelete();

            // The page, profile or account the ad appears as. Client-owned.
            $table->foreignId('identity_asset_id')->nullable()
                ->constrained('provider_assets')->nullOnDelete();

            $table->string('headline');
            $table->text('primary_text');
            $table->string('description')->nullable();
            $table->string('call_to_action', 48)->nullable();
            $table->string('destination_url');

            $table->string('provider_ad_id', 128)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error')->nullable();

            // Whatever the provider says about the ad's own review. Stored as
            // reported, never argued with (spec §27).
            $table->string('provider_review_status', 48)->nullable();
            $table->string('provider_review_detail')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['campaign_id', 'status']);
            $table->index(['ad_set_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ads_unique_provider_id
            ON ads (ad_set_id, provider_ad_id)
            WHERE provider_ad_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
