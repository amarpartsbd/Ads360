<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\Permission as P;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The system roles of spec §6.
 *
 * These are shared by every tenant (`tenant_id` is null) and marked
 * `is_system`, which makes them read-only to tenant administrators. A tenant
 * that needs something different creates its own role rather than editing one
 * of these.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['tenant_id' => null, 'slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ],
            );

            $role->syncPermissions($definition['permissions']);
        }
    }

    /**
     * @return list<array{slug: string, name: string, scope: RoleScope, description: string, permissions: list<P>}>
     */
    private function definitions(): array
    {
        return [
            // ---------------------------------------------------------------
            // Platform staff
            // ---------------------------------------------------------------
            [
                'slug' => 'super-admin',
                'name' => 'Super Admin',
                'scope' => RoleScope::Platform,
                'description' => 'Unrestricted platform access.',
                'permissions' => P::cases(),
            ],
            [
                'slug' => 'operations-admin',
                'name' => 'Operations Admin',
                'scope' => RoleScope::Platform,
                'description' => 'Day-to-day client and campaign operations.',
                'permissions' => [
                    P::ClientsView, P::ClientsCreate, P::ClientsUpdate,
                    P::CampaignsView, P::CampaignsApprove, P::CampaignsReject,
                    P::CampaignsPublish, P::CampaignsPause,
                    P::AdAccountsView, P::AdAccountsUpdate, P::AdAccountsAssign,
                    P::AdAccountsManageHealth,
                    P::AssetsView, P::CreativesView,
                    P::ReportsView, P::ReportsExport, P::SupportView,
                ],
            ],
            [
                'slug' => 'finance-admin',
                'name' => 'Finance Admin',
                'scope' => RoleScope::Platform,
                'description' => 'Wallets, payments, pricing and exchange rates.',
                'permissions' => [
                    P::ClientsView,
                    P::WalletView, P::WalletAdjust, P::WalletRefund,
                    P::PaymentsView, P::PaymentsVerify,
                    P::PricingView, P::PricingManage,
                    P::ExchangeRatesView, P::ExchangeRatesManage,
                    // Reads risk because it decides whether to release funds;
                    // does not hold RiskManage, and deliberately does not hold
                    // ApprovalsSenior — "Finance + Senior Approval" is two
                    // different kinds of person (spec §25).
                    P::RiskView,
                    P::ReportsView, P::ReportsExport, P::AuditView,
                ],
            ],
            [
                'slug' => 'compliance-admin',
                'name' => 'Compliance Admin',
                'scope' => RoleScope::Platform,
                'description' => 'Business verification, campaign review and risk.',
                'permissions' => [
                    P::ClientsView, P::ClientsVerify, P::ClientsSuspend,
                    P::CampaignsView, P::CampaignsApprove, P::CampaignsReject,
                    // Compliance owns the risk queue (spec §12).
                    P::RiskView, P::RiskManage,
                    P::AuditView, P::ReportsView,
                ],
            ],
            [
                'slug' => 'campaign-manager',
                'name' => 'Campaign Manager',
                'scope' => RoleScope::Platform,
                'description' => 'Reviews and manages campaigns. Holds no financial permissions.',
                'permissions' => [
                    P::ClientsView,
                    P::CampaignsView, P::CampaignsApprove, P::CampaignsReject,
                    P::CampaignsPublish, P::CampaignsPause,
                    P::AssetsView, P::CreativesView, P::ReportsView,
                ],
            ],
            [
                'slug' => 'media-buyer',
                'name' => 'Media Buyer',
                'scope' => RoleScope::Platform,
                'description' => 'Builds and operates campaigns on behalf of clients.',
                'permissions' => [
                    P::ClientsView,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsPause,
                    P::AssetsView, P::CreativesView, P::CreativesUpload, P::ReportsView,
                ],
            ],
            [
                'slug' => 'support-agent',
                'name' => 'Support Agent',
                'scope' => RoleScope::Platform,
                'description' => 'Answers client tickets. Cannot touch wallets or approvals.',
                'permissions' => [
                    P::ClientsView, P::CampaignsView,
                    P::SupportView, P::SupportRespond, P::ReportsView,
                ],
            ],

            // ---------------------------------------------------------------
            // Agency
            // ---------------------------------------------------------------
            [
                'slug' => 'agency-owner',
                'name' => 'Agency Owner',
                'scope' => RoleScope::Tenant,
                'description' => 'Full control of the agency and every client it manages.',
                'permissions' => [
                    P::ClientsView, P::ClientsCreate, P::ClientsUpdate,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit, P::CampaignsPause,
                    P::WalletView, P::WalletDeposit,
                    P::PaymentsView, P::PricingView,
                    P::AssetsView, P::AssetsConnect, P::AssetsDisconnect,
                    P::CreativesView, P::CreativesUpload, P::CreativesDelete,
                    P::ReportsView, P::ReportsExport,
                    // White label: an agency brands its own copy of the
                    // platform for the clients it manages (spec §43).
                    P::BrandingManage,
                    P::UsersManage, P::RolesManage, P::SupportView, P::AuditView,
                ],
            ],
            [
                'slug' => 'agency-admin',
                'name' => 'Agency Admin',
                'scope' => RoleScope::Tenant,
                'description' => 'Manages agency clients, campaigns and staff.',
                'permissions' => [
                    P::ClientsView, P::ClientsCreate, P::ClientsUpdate,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit, P::CampaignsPause,
                    P::WalletView, P::PaymentsView,
                    P::AssetsView, P::AssetsConnect,
                    P::CreativesView, P::CreativesUpload,
                    P::ReportsView, P::ReportsExport, P::UsersManage, P::SupportView,
                ],
            ],
            [
                'slug' => 'agency-manager',
                'name' => 'Agency Manager',
                'scope' => RoleScope::Organization,
                'description' => 'Runs campaigns for assigned clients.',
                'permissions' => [
                    P::ClientsView,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit, P::CampaignsPause,
                    P::AssetsView, P::CreativesView, P::CreativesUpload,
                    P::ReportsView, P::WalletView,
                ],
            ],
            [
                'slug' => 'agency-staff',
                'name' => 'Agency Staff',
                'scope' => RoleScope::Organization,
                'description' => 'Prepares campaigns and creatives for review.',
                'permissions' => [
                    P::ClientsView, P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate,
                    P::AssetsView, P::CreativesView, P::CreativesUpload, P::ReportsView,
                ],
            ],

            // ---------------------------------------------------------------
            // Client
            // ---------------------------------------------------------------
            [
                'slug' => 'client-owner',
                'name' => 'Client Owner',
                'scope' => RoleScope::Organization,
                'description' => 'Owns the organization: full access including team and billing.',
                'permissions' => [
                    P::ClientsView, P::ClientsUpdate,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit, P::CampaignsPause,
                    P::WalletView, P::WalletDeposit,
                    P::PaymentsView, P::PricingView,
                    P::AssetsView, P::AssetsConnect, P::AssetsDisconnect,
                    P::CreativesView, P::CreativesUpload, P::CreativesDelete,
                    P::ReportsView, P::ReportsExport,
                    P::UsersManage, P::RolesManage, P::SupportView, P::AuditView,
                ],
            ],
            [
                'slug' => 'client-admin',
                'name' => 'Client Admin',
                'scope' => RoleScope::Organization,
                'description' => 'Manages campaigns, assets and team members.',
                'permissions' => [
                    P::ClientsView, P::ClientsUpdate,
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit, P::CampaignsPause,
                    P::WalletView, P::WalletDeposit, P::PaymentsView,
                    P::AssetsView, P::AssetsConnect,
                    P::CreativesView, P::CreativesUpload,
                    P::ReportsView, P::ReportsExport, P::UsersManage, P::SupportView,
                ],
            ],
            [
                'slug' => 'client-marketer',
                'name' => 'Client Marketer',
                'scope' => RoleScope::Organization,
                'description' => 'Builds and submits campaigns. No wallet or team access.',
                'permissions' => [
                    P::CampaignsView, P::CampaignsCreate, P::CampaignsUpdate, P::CampaignsSubmit,
                    P::AssetsView, P::CreativesView, P::CreativesUpload,
                    P::ReportsView, P::SupportView,
                ],
            ],
            [
                'slug' => 'client-accountant',
                'name' => 'Client Accountant',
                'scope' => RoleScope::Organization,
                'description' => 'Finance only: funds the wallet and reads invoices. Cannot approve campaigns.',
                'permissions' => [
                    P::WalletView, P::WalletDeposit,
                    P::PaymentsView, P::PricingView,
                    P::ReportsView, P::ReportsExport, P::CampaignsView,
                ],
            ],
            [
                'slug' => 'client-viewer',
                'name' => 'Client Viewer',
                'scope' => RoleScope::Organization,
                'description' => 'Read-only access to campaigns and reports.',
                'permissions' => [
                    P::CampaignsView, P::AssetsView, P::CreativesView, P::ReportsView,
                ],
            ],
        ];
    }
}
