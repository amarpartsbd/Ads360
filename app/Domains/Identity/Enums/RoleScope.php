<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * The level a role is granted at.
 *
 * PLATFORM roles belong to platform staff and carry no tenant. TENANT roles
 * apply across every organization of one tenant — an agency owner, say.
 * ORGANIZATION roles apply to a single organization.
 */
enum RoleScope: string
{
    case Platform = 'PLATFORM';
    case Tenant = 'TENANT';
    case Organization = 'ORGANIZATION';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Tenant => 'Tenant',
            self::Organization => 'Organization',
        };
    }

    /** Whether a grant of this scope must name an organization. */
    public function requiresOrganization(): bool
    {
        return $this === self::Organization;
    }
}
