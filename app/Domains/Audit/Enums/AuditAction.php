<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

/**
 * The critical events of spec §51.
 *
 * Actions are an enum rather than free strings so audit queries and alerting
 * can rely on a fixed vocabulary. Modules from later phases add their cases
 * here as they land.
 */
enum AuditAction: string
{
    // Authentication
    case LoginSucceeded = 'auth.login.succeeded';
    case LoginFailed = 'auth.login.failed';
    case LoginBlocked = 'auth.login.blocked';
    case LogoutPerformed = 'auth.logout';
    case PasswordChanged = 'auth.password.changed';
    case PasswordResetRequested = 'auth.password.reset_requested';
    case PasswordReset = 'auth.password.reset';
    case TwoFactorEnabled = 'auth.two_factor.enabled';
    case TwoFactorDisabled = 'auth.two_factor.disabled';
    case TwoFactorRecoveryCodesRegenerated = 'auth.two_factor.recovery_codes_regenerated';
    case SessionRevoked = 'auth.session.revoked';
    case DeviceTrusted = 'auth.device.trusted';

    // Identity and access
    case UserRegistered = 'identity.user.registered';
    case UserInvited = 'identity.user.invited';
    case UserSuspended = 'identity.user.suspended';
    case UserReinstated = 'identity.user.reinstated';
    case UserRemoved = 'identity.user.removed';
    case RoleAssigned = 'identity.role.assigned';
    case RoleRevoked = 'identity.role.revoked';
    case RoleCreated = 'identity.role.created';
    case RoleUpdated = 'identity.role.updated';
    case RoleDeleted = 'identity.role.deleted';

    // Tenancy
    case TenantCreated = 'tenant.created';
    case TenantUpdated = 'tenant.updated';
    case TenantSuspended = 'tenant.suspended';
    case OrganizationCreated = 'tenant.organization.created';
    case OrganizationUpdated = 'tenant.organization.updated';
    case OrganizationSuspended = 'tenant.organization.suspended';
    case MembershipCreated = 'tenant.membership.created';
    case MembershipUpdated = 'tenant.membership.updated';
    case MembershipRevoked = 'tenant.membership.revoked';
    case InvitationSent = 'tenant.invitation.sent';
    case InvitationResent = 'tenant.invitation.resent';
    case InvitationAccepted = 'tenant.invitation.accepted';
    case InvitationRevoked = 'tenant.invitation.revoked';

    // Compliance
    case VerificationSubmitted = 'compliance.verification.submitted';
    case VerificationClaimed = 'compliance.verification.claimed';
    case ClientVerificationApproved = 'compliance.verification.approved';
    case ClientVerificationRejected = 'compliance.verification.rejected';
    case VerificationInformationRequested = 'compliance.verification.information_requested';
    case VerificationSuspended = 'compliance.verification.suspended';
    case VerificationDocumentUploaded = 'compliance.document.uploaded';
    case VerificationDocumentDeleted = 'compliance.document.deleted';
    case VerificationDocumentDownloaded = 'compliance.document.downloaded';

    // Finance
    case DepositSubmitted = 'finance.deposit.submitted';
    case DepositApproved = 'finance.deposit.approved';
    case DepositRejected = 'finance.deposit.rejected';
    case PaymentInitiated = 'finance.payment.initiated';
    case PaymentFailed = 'finance.payment.failed';
    case WalletAdjusted = 'finance.wallet.adjusted';
    case WalletFrozen = 'finance.wallet.frozen';
    case WalletUnfrozen = 'finance.wallet.unfrozen';
    case RefundIssued = 'finance.refund.issued';
    case LedgerEntryReversed = 'finance.ledger.reversed';
    case BudgetReserved = 'finance.budget.reserved';
    case BudgetReleased = 'finance.budget.released';
    case PricingChanged = 'finance.pricing.changed';
    case ExchangeRateChanged = 'finance.exchange_rate.changed';
    case InvoiceIssued = 'finance.invoice.issued';
    case InvoiceVoided = 'finance.invoice.voided';

    // Advertising integrations (spec §16, §51)
    case ProviderConnected = 'integration.provider.connected';
    case ProviderReconnected = 'integration.provider.reconnected';
    case ProviderDisconnected = 'integration.provider.disconnected';
    case ProviderConnectionExpired = 'integration.provider.expired';
    case ProviderConnectionRevoked = 'integration.provider.revoked';
    case ProviderAssetsSynced = 'integration.provider.assets_synced';
    case OAuthStateRejected = 'integration.oauth.state_rejected';
    case WebhookReceived = 'integration.webhook.received';
    case WebhookRejected = 'integration.webhook.rejected';
    case WebhookProcessed = 'integration.webhook.processed';

    // Managed ad infrastructure (spec §17, §18, §20)
    case AdAccountCreated = 'ad_account.created';
    case AdAccountUpdated = 'ad_account.updated';
    case AdAccountStatusChanged = 'ad_account.status_changed';
    case AdAccountHealthChanged = 'ad_account.health_changed';
    case AdAccountAssigned = 'ad_account.assigned';
    case AdAccountPoolCreated = 'ad_account.pool.created';
    case AdAccountPoolUpdated = 'ad_account.pool.updated';
    case AdAccountPoolMembershipChanged = 'ad_account.pool.membership_changed';

    // Campaigns (spec §21, §22)
    case CampaignCreated = 'campaign.created';
    case CampaignUpdated = 'campaign.updated';
    case CampaignSubmitted = 'campaign.submitted';
    case CampaignApproved = 'campaign.approved';
    case CampaignRejected = 'campaign.rejected';
    case CampaignChangesRequested = 'campaign.changes_requested';
    case CampaignPublished = 'campaign.published';
    case CampaignPublishFailed = 'campaign.publish_failed';
    case CampaignPaused = 'campaign.paused';
    case CampaignResumed = 'campaign.resumed';
    case CampaignCompleted = 'campaign.completed';
    case CampaignArchived = 'campaign.archived';
    case CampaignSpendCaptured = 'campaign.spend_captured';
    case AdAccountAllocated = 'campaign.ad_account_allocated';
    case CreativeUploaded = 'campaign.creative.uploaded';
    case CreativeDeleted = 'campaign.creative.deleted';
    case CreativeDownloaded = 'campaign.creative.downloaded';

    // Analytics and reconciliation (spec §38, §78)
    case SpendDiscrepancyFound = 'analytics.reconciliation.discrepancy';
    case SpendDiscrepancyResolved = 'analytics.reconciliation.resolved';
    case ReportExported = 'analytics.report.exported';
    case ReportDownloaded = 'analytics.report.downloaded';

    // Maker-checker (spec §25)
    case ApprovalRequested = 'governance.approval.requested';
    case ApprovalGranted = 'governance.approval.granted';
    case ApprovalRejected = 'governance.approval.rejected';

    // Security
    case UnauthorizedAccessAttempt = 'security.unauthorized_access';
    case StepUpAuthenticationPassed = 'security.step_up.passed';

    public function isSecurityEvent(): bool
    {
        return str_starts_with($this->value, 'auth.')
            || str_starts_with($this->value, 'security.');
    }

    /**
     * Financial events are retained and reviewed differently from the rest:
     * they are what a reconciliation or a dispute is argued from.
     */
    public function isFinancialEvent(): bool
    {
        return str_starts_with($this->value, 'finance.');
    }
}
