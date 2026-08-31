<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Models\Wallet;

/**
 * Authorization for wallets and the ledger (spec §68).
 *
 * A client may look at their own wallet. Nobody outside the platform may move
 * money in it by hand: adjusting and refunding are platform-only and gated on
 * permissions no client role holds.
 */
final class WalletPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::WalletView, $this->context->organization());
    }

    public function view(User $user, Wallet $wallet): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::WalletView);
        }

        return $this->actsWithin($user, $wallet)
            && $user->hasPermissionTo(Permission::WalletView, $this->context->organization());
    }

    /** Submitting a deposit against the wallet. */
    public function deposit(User $user, Wallet $wallet): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $this->actsWithin($user, $wallet)
            && $user->hasPermissionTo(Permission::WalletDeposit, $this->context->organization());
    }

    /**
     * Manual balance corrections. Platform-only: a client adjusting their own
     * balance is the same thing as printing money.
     */
    public function adjust(User $user, Wallet $wallet): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::WalletAdjust);
    }

    public function refund(User $user, Wallet $wallet): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::WalletRefund);
    }

    /** Freezing a wallet stops outflow and is a compliance action. */
    public function freeze(User $user, Wallet $wallet): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::WalletAdjust);
    }

    private function actsWithin(User $user, Wallet $wallet): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $wallet->tenant_id
            && $organization !== null
            && $organization->getKey() === $wallet->organization_id
            && $user->canReachOrganization($organization);
    }
}
