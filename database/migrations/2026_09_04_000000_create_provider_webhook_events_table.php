<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound webhooks from advertising providers (spec §52).
 *
 * Every accepted delivery is recorded before it is acted on, for two reasons.
 *
 * Providers redeliver. Meta retries a webhook it did not get a 200 for, and a
 * platform that processed each delivery blindly would act on the same event
 * several times — which for a spend notification means charging a client
 * repeatedly. The unique index on the signature makes a redelivery detectable.
 *
 * And a webhook is an assertion by an outside party about a client's money. If
 * one is ever wrong, the record of exactly what arrived and when is the only
 * way to work out what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 32)->index();

            // Meta's `object` field: which kind of thing the update is about.
            $table->string('object_type', 64)->nullable();

            /*
             * A digest of the raw body. Providers do not all send a delivery
             * id, and a digest is available for every provider — so it is what
             * duplicate detection keys on.
             */
            $table->string('payload_digest', 64);

            $table->string('status', 32)->index();

            $table->jsonb('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['provider', 'status', 'received_at']);
        });

        /*
         * One row per distinct body. A redelivery of the same payload finds
         * the existing row instead of creating work.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_webhook_events_unique_delivery
            ON provider_webhook_events (provider, payload_digest)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
    }
};
