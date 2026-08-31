<?php

declare(strict_types=1);

namespace App\Domains\Agency\Exceptions;

use App\Domains\Tenant\Models\Tenant;
use RuntimeException;

/**
 * A refusal from the agency module (spec §42).
 *
 * The messages are written for the person who hit them, because every one of
 * these is something a human did rather than a system failure.
 */
final class AgencyException extends RuntimeException
{
    public static function notAnAgency(Tenant $tenant): self
    {
        return new self(
            "[{$tenant->name}] is a {$tenant->type->label()} and does not manage clients."
        );
    }

    public static function moduleDisabled(): self
    {
        return new self('The agency module is not enabled on this platform.');
    }

    /**
     * The one that matters. An agency acting on an organization belonging to
     * another agency is the failure §42 names outright, so it is refused with
     * a message that does not confirm the organization exists.
     */
    public static function notYourClient(): self
    {
        return new self('That client does not belong to your agency.');
    }

    public static function notYourStaff(): self
    {
        return new self('That person is not a member of your agency.');
    }

    public static function staffRoleUnavailable(string $slug): self
    {
        return new self(
            "The [{$slug}] role is missing. Run the role seeder before assigning agency staff."
        );
    }

    /**
     * A client organization cannot be the agency's own house account: an
     * agency assigning staff to itself as though it were a client would put a
     * membership row where the tenant-wide grant already reaches.
     */
    public static function houseAccountIsNotAClient(): self
    {
        return new self('The agency\'s own account is not one of its clients.');
    }
}
