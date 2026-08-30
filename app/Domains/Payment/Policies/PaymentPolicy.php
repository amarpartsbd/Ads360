<?php

declare(strict_types=1);

namespace App\Domains\Payment\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for payments (spec §68).
 *
 * The rule that matters: a client submits a deposit, and someone else verifies
 * it. `payments.verify` is platform-only, so no client can confirm their own
 * money into the ledger.
 */
final class PaymentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::PaymentsView, $this->context->organization());
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::PaymentsView);
        }

        return $this->actsWithin($user, $payment)
            && $user->hasPermissionTo(Permission::PaymentsView, $this->context->organization());
    }

    public function create(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $user->hasPermissionTo(Permission::WalletDeposit, $this->context->organization());
    }

    /** Confirming that money actually arrived. Platform-only. */
    public function verify(User $user, Payment $payment): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::PaymentsVerify);
    }

    public function downloadProof(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }

    private function actsWithin(User $user, Payment $payment): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $payment->tenant_id
            && $organization !== null
            && $organization->getKey() === $payment->organization_id
            && $user->belongsToOrganization($organization);
    }
}
