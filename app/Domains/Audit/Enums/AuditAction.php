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

    // Security
    case UnauthorizedAccessAttempt = 'security.unauthorized_access';
    case StepUpAuthenticationPassed = 'security.step_up.passed';

    public function isSecurityEvent(): bool
    {
        return str_starts_with($this->value, 'auth.')
            || str_starts_with($this->value, 'security.');
    }
}
