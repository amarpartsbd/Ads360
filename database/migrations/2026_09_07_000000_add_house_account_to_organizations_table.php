<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which organization is the agency itself (spec §42).
 *
 * An agency tenant holds one organization per client it manages, plus one for
 * the agency's own business — where its staff sit, where its own billing goes,
 * and where it runs its own advertising if it does. Without a way to tell them
 * apart, an agency's client list would include the agency, and assigning staff
 * to "a client" could quietly mean the agency itself.
 *
 * A boolean rather than a separate table: it is a property of the organization,
 * not a relationship, and a client that is later promoted to running its own
 * agency gets a new tenant rather than a flipped flag.
 *
 * Direct-client tenants leave it false. Their single organization *is* the
 * client; there is nothing for it to be the house account of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('is_house_account')->default(false);
        });

        /*
         * One house account per tenant, enforced here rather than in an action.
         * Two would make "the agency's own workspace" ambiguous everywhere it
         * is read, and the ambiguity would surface as a client list that
         * sometimes hides a real client.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organizations_one_house_per_tenant
            ON organizations (tenant_id)
            WHERE is_house_account = true AND deleted_at IS NULL
        SQL);

        $table = 'organizations';
        DB::statement("CREATE INDEX organizations_agency_clients ON {$table} (tenant_id, is_house_account)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_agency_clients');
        DB::statement('DROP INDEX IF EXISTS organizations_one_house_per_tenant');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('is_house_account');
        });
    }
};
