<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * The platform's permission registry (spec §7).
 *
 * Every authorization decision resolves to one of these. Roles are just named
 * bundles of them, so no code branches on a role name.
 *
 * Permissions for modules that arrive in later phases are declared here up
 * front: the seeder writes them all, and policies adopt them as each module
 * lands. That keeps the vocabulary stable instead of renaming permissions later.
 */
enum Permission: string
{
    // Clients and organizations
    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientsVerify = 'clients.verify';
    case ClientsSuspend = 'clients.suspend';

    // Campaigns
    case CampaignsView = 'campaigns.view';
    case CampaignsCreate = 'campaigns.create';
    case CampaignsUpdate = 'campaigns.update';
    case CampaignsSubmit = 'campaigns.submit';
    case CampaignsApprove = 'campaigns.approve';
    case CampaignsReject = 'campaigns.reject';
    case CampaignsPublish = 'campaigns.publish';
    case CampaignsPause = 'campaigns.pause';

    // Wallet
    case WalletView = 'wallet.view';
    case WalletDeposit = 'wallet.deposit';
    case WalletAdjust = 'wallet.adjust';
    case WalletRefund = 'wallet.refund';

    // Payments
    case PaymentsView = 'payments.view';
    case PaymentsVerify = 'payments.verify';

    // Ad accounts
    case AdAccountsView = 'ad_accounts.view';
    case AdAccountsCreate = 'ad_accounts.create';
    case AdAccountsUpdate = 'ad_accounts.update';
    case AdAccountsAssign = 'ad_accounts.assign';
    case AdAccountsManageHealth = 'ad_accounts.manage_health';
    case AdAccountsManagePools = 'ad_accounts.manage_pools';

    // Pricing and exchange rates
    case PricingView = 'pricing.view';
    case PricingManage = 'pricing.manage';
    case ExchangeRatesView = 'exchange_rates.view';
    case ExchangeRatesManage = 'exchange_rates.manage';

    // Reporting
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // Governance
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';

    // Connected advertising assets
    case AssetsView = 'assets.view';
    case AssetsConnect = 'assets.connect';
    case AssetsDisconnect = 'assets.disconnect';

    // Creative library
    case CreativesView = 'creatives.view';
    case CreativesUpload = 'creatives.upload';
    case CreativesDelete = 'creatives.delete';

    // Support
    case SupportView = 'support.view';
    case SupportRespond = 'support.respond';

    // Platform system administration
    case SystemManage = 'system.manage';
    case SettingsManage = 'settings.manage';

    public function group(): string
    {
        return str_contains($this->value, '.')
            ? strstr($this->value, '.', true)
            : 'general';
    }

    /**
     * Privileged permissions gate financially or securely significant actions.
     * They require step-up authentication (spec §9) and are the candidates for
     * maker-checker control (spec §25).
     */
    public function isPrivileged(): bool
    {
        return in_array($this, [
            self::WalletAdjust,
            self::WalletRefund,
            self::PaymentsVerify,
            self::PricingManage,
            self::ExchangeRatesManage,
            self::RolesManage,
            self::UsersManage,
            self::SystemManage,
            self::SettingsManage,
            self::ClientsSuspend,
            self::AdAccountsAssign,
            self::AdAccountsManagePools,
        ], true);
    }

    public function description(): string
    {
        return match ($this) {
            self::ClientsView => 'View client organizations',
            self::ClientsCreate => 'Create client organizations',
            self::ClientsUpdate => 'Update client organizations',
            self::ClientsVerify => 'Approve or reject business verification',
            self::ClientsSuspend => 'Suspend a client organization',
            self::CampaignsView => 'View campaigns',
            self::CampaignsCreate => 'Create campaigns',
            self::CampaignsUpdate => 'Edit campaigns',
            self::CampaignsSubmit => 'Submit campaigns for review',
            self::CampaignsApprove => 'Approve submitted campaigns',
            self::CampaignsReject => 'Reject submitted campaigns',
            self::CampaignsPublish => 'Publish campaigns to a provider',
            self::CampaignsPause => 'Pause or resume campaigns',
            self::WalletView => 'View wallet balances and ledger',
            self::WalletDeposit => 'Submit wallet deposits',
            self::WalletAdjust => 'Post manual wallet adjustments',
            self::WalletRefund => 'Issue refunds',
            self::PaymentsView => 'View payments',
            self::PaymentsVerify => 'Verify and approve payments',
            self::AdAccountsView => 'View managed ad accounts',
            self::AdAccountsCreate => 'Add managed ad accounts',
            self::AdAccountsUpdate => 'Update managed ad account settings and limits',
            self::AdAccountsAssign => 'Assign ad accounts to campaigns or clients',
            self::AdAccountsManageHealth => 'Manage ad account health and limits',
            self::AdAccountsManagePools => 'Manage ad account pools and allocation rules',
            self::PricingView => 'View pricing plans',
            self::PricingManage => 'Change pricing plans and fees',
            self::ExchangeRatesView => 'View exchange rates',
            self::ExchangeRatesManage => 'Change exchange rates',
            self::ReportsView => 'View reports',
            self::ReportsExport => 'Export reports',
            self::AuditView => 'View audit logs',
            self::UsersManage => 'Invite, suspend and remove users',
            self::RolesManage => 'Create roles and change role assignments',
            self::AssetsView => 'View connected advertising assets',
            self::AssetsConnect => 'Connect advertising assets',
            self::AssetsDisconnect => 'Disconnect advertising assets',
            self::CreativesView => 'View the creative library',
            self::CreativesUpload => 'Upload creatives',
            self::CreativesDelete => 'Delete creatives',
            self::SupportView => 'View support tickets',
            self::SupportRespond => 'Respond to support tickets',
            self::SystemManage => 'Manage system health, queues and integrations',
            self::SettingsManage => 'Manage platform settings',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
