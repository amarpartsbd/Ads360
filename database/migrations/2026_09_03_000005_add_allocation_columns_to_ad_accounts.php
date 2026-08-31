<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when an account was last handed out (spec §19).
 *
 * Needed by the round-robin strategy, which otherwise has no way to take
 * accounts in turn, and useful on its own: an account that has not been
 * allocated in months is either mispriced or misconfigured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->timestamp('last_allocated_at')->nullable()->after('last_synced_at');

            // Ordering for round robin: never-allocated accounts first, then
            // least recently used.
            $table->index(['status', 'last_allocated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'last_allocated_at']);
            $table->dropColumn('last_allocated_at');
        });
    }
};
