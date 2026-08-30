<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Enums;

/**
 * The kind of business a tenant is (spec §5).
 */
enum TenantType: string
{
    case DirectClient = 'DIRECT_CLIENT';
    case Agency = 'AGENCY';
    case Reseller = 'RESELLER';
    case Enterprise = 'ENTERPRISE';

    public function label(): string
    {
        return match ($this) {
            self::DirectClient => 'Direct client',
            self::Agency => 'Agency',
            self::Reseller => 'Reseller',
            self::Enterprise => 'Enterprise',
        };
    }

    /** Agencies and resellers manage organizations on behalf of other businesses. */
    public function managesClients(): bool
    {
        return in_array($this, [self::Agency, self::Reseller, self::Enterprise], true);
    }
}
