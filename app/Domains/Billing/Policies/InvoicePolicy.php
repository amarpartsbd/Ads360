<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for invoices (spec §37, §68).
 *
 * Nobody may update or delete one — finalised documents are corrected with a
 * credit note, and the model enforces that too.
 */
final class InvoicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::PaymentsView, $this->context->organization());
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::PaymentsView);
        }

        $organization = $this->context->organization();

        return $user->tenant_id === $invoice->tenant_id
            && $organization !== null
            && $organization->getKey() === $invoice->organization_id
            && $user->hasPermissionTo(Permission::PaymentsView, $organization);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::PricingManage);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::WalletRefund);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
