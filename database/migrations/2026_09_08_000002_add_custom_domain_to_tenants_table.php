<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's own domain (spec §43).
 *
 * Separate from the branding document rather than another key inside it,
 * because a domain is the one branding value the platform has to *look up*: a
 * request arriving at a white-labelled host has to find its tenant before
 * anything else happens, and an index on a JSON key is a worse answer than a
 * column.
 *
 * Storing it does not serve it. Terminating TLS for a customer's domain is
 * infrastructure — a certificate, a DNS record, a load balancer — and this
 * column is the platform's half of that arrangement. The deployment notes say
 * what the other half is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('custom_domain', 253)->nullable();
        });

        /*
         * One tenant per domain, and only among live tenants. Partial so a
         * soft-deleted tenant does not hold a domain hostage: a customer who
         * left and came back should be able to keep their own address.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tenants_unique_custom_domain
            ON tenants (custom_domain)
            WHERE custom_domain IS NOT NULL AND deleted_at IS NULL
        SQL);

        /*
         * Lower case, no scheme, no path, no port. Enforced here because this
         * value is compared against an incoming Host header, and a stored
         * "https://Example.com/" would simply never match anything while
         * looking perfectly correct in the settings screen.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE tenants
            ADD CONSTRAINT tenants_custom_domain_is_a_hostname
            CHECK (
                custom_domain IS NULL
                OR custom_domain ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_custom_domain_is_a_hostname');
        DB::statement('DROP INDEX IF EXISTS tenants_unique_custom_domain');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('custom_domain');
        });
    }
};
