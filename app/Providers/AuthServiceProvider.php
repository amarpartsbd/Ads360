<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Policies\AdAccountPolicy;
use App\Domains\Advertising\Policies\AdAccountPoolPolicy;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Policies\AuditLogPolicy;
use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Policies\ExchangeRatePolicy;
use App\Domains\Billing\Policies\InvoicePolicy;
use App\Domains\Billing\Policies\PricingPlanPolicy;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Compliance\Policies\VerificationDocumentPolicy;
use App\Domains\Compliance\Policies\VerificationProfilePolicy;
use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Policies\RolePolicy;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Policies\ProviderAssetPolicy;
use App\Domains\Integration\Policies\ProviderConnectionPolicy;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Policies\PaymentPolicy;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Policies\ApprovalRequestPolicy;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Policies\OrganizationPolicy;
use App\Domains\Tenant\Policies\TenantPolicy;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Policies\WalletPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Tenant::class => TenantPolicy::class,
        Organization::class => OrganizationPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        VerificationProfile::class => VerificationProfilePolicy::class,
        VerificationDocument::class => VerificationDocumentPolicy::class,
        Wallet::class => WalletPolicy::class,
        Payment::class => PaymentPolicy::class,
        Invoice::class => InvoicePolicy::class,
        ExchangeRate::class => ExchangeRatePolicy::class,
        PricingPlan::class => PricingPlanPolicy::class,
        ApprovalRequest::class => ApprovalRequestPolicy::class,
        ProviderConnection::class => ProviderConnectionPolicy::class,
        ProviderAsset::class => ProviderAssetPolicy::class,
        AdAccount::class => AdAccountPolicy::class,
        AdAccountPool::class => AdAccountPoolPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Every permission in the registry becomes a gate of the same name, so
        // `$user->can('campaigns.approve')` works anywhere without a policy.
        // Checks resolve against the organization currently in context.
        foreach (PermissionEnum::cases() as $permission) {
            Gate::define(
                $permission->value,
                function (User $user, ?Organization $organization = null) use ($permission): bool {
                    $organization ??= app(TenantContext::class)->organization();

                    // A non-platform user may only be checked against an
                    // organization they actually belong to.
                    if ($organization !== null
                        && ! $user->isPlatformUser()
                        && ! $user->belongsToOrganization($organization)) {
                        return false;
                    }

                    return $user->hasPermissionTo($permission, $organization);
                }
            );
        }
    }
}
