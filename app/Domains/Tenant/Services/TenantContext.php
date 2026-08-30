<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Services;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use RuntimeException;

/**
 * The tenant and organization the current request operates in.
 *
 * This is resolved server-side from the authenticated user's membership and is
 * the only thing the rest of the application is allowed to trust. A tenant or
 * organization identifier arriving in a URL, body or header is treated as an
 * untrusted claim to be verified, never as context (spec §5).
 *
 * Registered as a singleton, so it lives for exactly one request or one job.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Organization $organization = null;

    /**
     * True once a tenant-bound identity has been resolved. Used to distinguish
     * "platform staff, deliberately unscoped" from "context was never set",
     * which would otherwise look identical to a query scope.
     */
    private bool $bound = false;

    public function for(Tenant $tenant, ?Organization $organization = null): self
    {
        if ($organization !== null && $organization->tenant_id !== $tenant->getKey()) {
            throw new RuntimeException(
                'Refusing to bind an organization that belongs to a different tenant.'
            );
        }

        $this->tenant = $tenant;
        $this->organization = $organization;
        $this->bound = true;

        return $this;
    }

    public function withOrganization(Organization $organization): self
    {
        if ($this->tenant === null) {
            throw new RuntimeException('Cannot select an organization before a tenant is bound.');
        }

        return $this->for($this->tenant, $organization);
    }

    public function forget(): self
    {
        $this->tenant = null;
        $this->organization = null;
        $this->bound = false;

        return $this;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function organizationId(): ?int
    {
        return $this->organization?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function hasOrganization(): bool
    {
        return $this->organization !== null;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function requireTenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant is bound to the current context.');
    }

    public function requireOrganization(): Organization
    {
        return $this->organization
            ?? throw new RuntimeException('No organization is bound to the current context.');
    }

    /**
     * Run a callback with the context temporarily replaced, restoring whatever
     * was bound before. Used by queued jobs and by platform staff acting on a
     * specific tenant, so context never leaks past the operation.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, ?Organization $organization, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousOrganization = $this->organization;
        $previousBound = $this->bound;

        $this->for($tenant, $organization);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->organization = $previousOrganization;
            $this->bound = $previousBound;
        }
    }
}
