<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of an ad account's headroom this campaign is currently holding
 * (spec §19).
 *
 * Kept on the campaign rather than derived, because the account's
 * `committed_amount` is a sum over many campaigns and there is no way to
 * subtract one campaign's share from it without knowing what that share is.
 *
 * Without this column, releasing headroom as a campaign spends would subtract
 * the same amount again on every sync — the account would appear to free up
 * capacity it never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->bigInteger('account_commitment')->default(0)->after('reported_spend');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE campaigns
            ADD CONSTRAINT campaigns_account_commitment_non_negative
            CHECK (account_commitment >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('account_commitment');
        });
    }
};
