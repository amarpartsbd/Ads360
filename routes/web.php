<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdAccountController;
use App\Http\Controllers\Admin\AdAccountPoolController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CampaignReviewController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DepositController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Client\AnalyticsController;
use App\Http\Controllers\Client\AssetController;
use App\Http\Controllers\Client\CampaignController;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\Client\CreativeController;
use App\Http\Controllers\Client\OrganizationSettingsController;
use App\Http\Controllers\Client\OrganizationSwitchController;
use App\Http\Controllers\Client\ProviderOAuthController;
use App\Http\Controllers\Client\SecurityController;
use App\Http\Controllers\Client\SimulatedConsentController;
use App\Http\Controllers\Client\TeamController;
use App\Http\Controllers\Client\VerificationController;
use App\Http\Controllers\Client\WalletController;
use App\Http\Controllers\Shared\CreativeDownloadController;
use App\Http\Controllers\Shared\PaymentProofDownloadController;
use App\Http\Controllers\Shared\ReportDownloadController;
use App\Http\Controllers\Shared\VerificationDocumentDownloadController;
use App\Http\Controllers\Shared\WelcomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', WelcomeController::class)->name('welcome');

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
|
| Reachable without authentication: the invitee may not have an account yet.
| The token itself is the credential, and the accepting action re-validates it.
| Rate limited, because the endpoint takes a secret from an untrusted source.
|
*/

Route::middleware('throttle:6,1')->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

/*
|--------------------------------------------------------------------------
| Client application — /app (spec §92)
|--------------------------------------------------------------------------
|
| Every route here requires an authenticated, verified user with a resolved
| tenant context. `tenant` fails closed: without context the request is
| refused rather than served unscoped.
|
*/

Route::middleware(['auth', 'verified', 'tenant'])
    ->prefix('app')
    ->name('client.')
    ->group(function (): void {
        Route::get('dashboard', ClientDashboardController::class)->name('dashboard');

        Route::post('organization/switch', OrganizationSwitchController::class)
            ->name('organization.switch');

        // Business verification (spec §11).
        Route::get('verification', [VerificationController::class, 'show'])->name('verification.show');
        Route::put('verification', [VerificationController::class, 'update'])->name('verification.update');
        Route::post('verification/documents', [VerificationController::class, 'storeDocument'])
            ->name('verification.documents.store');
        Route::delete('verification/documents/{document}', [VerificationController::class, 'destroyDocument'])
            ->name('verification.documents.destroy');
        Route::get('verification/documents/{document}', VerificationDocumentDownloadController::class)
            ->name('verification.documents.download');

        // Team management (spec §82).
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invite');
        Route::post('team/invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])
            ->name('team.invitations.resend');
        Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])
            ->name('team.invitations.revoke');
        Route::put('team/members/{member}/roles', [TeamController::class, 'updateRoles'])
            ->name('team.members.roles');
        Route::post('team/members/{member}/suspend', [TeamController::class, 'suspend'])
            ->name('team.members.suspend');
        Route::post('team/members/{member}/reinstate', [TeamController::class, 'reinstate'])
            ->name('team.members.reinstate');
        Route::delete('team/members/{member}', [TeamController::class, 'remove'])
            ->name('team.members.remove');

        /*
         * Wallet and billing (spec §14).
         *
         * Every amount rendered here is computed and formatted server-side; the
         * browser never performs money arithmetic (Rule 8).
         */
        Route::get('wallet', [WalletController::class, 'overview'])->name('wallet.overview');
        Route::get('wallet/transactions', [WalletController::class, 'transactions'])
            ->name('wallet.transactions');
        Route::get('wallet/add-funds', [WalletController::class, 'deposits'])->name('wallet.add-funds');
        Route::post('wallet/deposits', [WalletController::class, 'storeDeposit'])
            ->name('wallet.deposits.store');
        Route::get('wallet/invoices', [WalletController::class, 'invoices'])->name('wallet.invoices');
        Route::get('wallet/payments/{payment}/proof', PaymentProofDownloadController::class)
            ->name('wallet.payments.proof');

        // Where a gateway returns the client after a hosted checkout.
        Route::get('wallet/payments/{payment}/return', [WalletController::class, 'overview'])
            ->name('wallet.payments.return');

        /*
         * Campaigns (spec §21).
         *
         * Building is the client's; approving is not. There is no route here
         * that changes a campaign's own review outcome.
         */
        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::post('campaigns/{campaign}/submit', [CampaignController::class, 'submit'])
            ->name('campaigns.submit');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])
            ->name('campaigns.pause');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])
            ->name('campaigns.resume');

        Route::post('campaigns/{campaign}/ad-sets', [CampaignController::class, 'storeAdSet'])
            ->name('campaigns.ad-sets.store');
        Route::delete('campaigns/{campaign}/ad-sets/{adSet}', [CampaignController::class, 'destroyAdSet'])
            ->name('campaigns.ad-sets.destroy');
        Route::post('campaigns/{campaign}/ad-sets/{adSet}/ads', [CampaignController::class, 'storeAd'])
            ->name('campaigns.ads.store');
        Route::delete('campaigns/{campaign}/ads/{ad}', [CampaignController::class, 'destroyAd'])
            ->name('campaigns.ads.destroy');

        /*
         * Analytics and reports (spec §38, §39).
         *
         * Exports are queued, never generated in the request: a year of a busy
         * client's data is slow by nature.
         */
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::post('analytics/exports', [AnalyticsController::class, 'export'])
            ->middleware('throttle:10,1')
            ->name('analytics.exports.store');
        Route::get('analytics/exports/{export}/download', ReportDownloadController::class)
            ->name('analytics.exports.download');

        // Creative library (spec §23).
        Route::get('creatives', [CreativeController::class, 'index'])->name('creatives.index');
        Route::post('creatives', [CreativeController::class, 'store'])->name('creatives.store');
        Route::delete('creatives/{creative}', [CreativeController::class, 'destroy'])
            ->name('creatives.destroy');
        Route::get('creatives/{creative}/download', CreativeDownloadController::class)
            ->name('creatives.download');

        /*
         * Connected advertising assets (spec §15, §16).
         *
         * The OAuth round trip is throttled: `start` mints a state row on every
         * call, and `callback` takes a value from an untrusted redirect.
         */
        Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
        Route::post('assets/connections/{connection}/sync', [AssetController::class, 'sync'])
            ->name('assets.connections.sync');
        Route::delete('assets/connections/{connection}', [AssetController::class, 'disconnect'])
            ->name('assets.connections.disconnect');

        Route::middleware('throttle:10,1')->group(function (): void {
            Route::get('assets/connect/{provider}', [ProviderOAuthController::class, 'start'])
                ->name('assets.oauth.start');
            Route::get('assets/callback/{provider}', [ProviderOAuthController::class, 'callback'])
                ->name('assets.oauth.callback');

            // Development stand-in for a provider consent screen (spec §95).
            // The controller refuses to answer in production.
            Route::get('assets/simulate/{provider}', [SimulatedConsentController::class, 'show'])
                ->name('assets.oauth.simulate');
            Route::post('assets/simulate/{provider}', [SimulatedConsentController::class, 'submit'])
                ->name('assets.oauth.simulate.submit');
        });

        // Settings.
        Route::get('settings/organization', [OrganizationSettingsController::class, 'edit'])
            ->name('settings.organization');
        Route::put('settings/organization', [OrganizationSettingsController::class, 'update'])
            ->name('settings.organization.update');
        Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::delete('settings/sessions/{session}', [SecurityController::class, 'destroySession'])
            ->name('security.sessions.destroy');
    });

/*
|--------------------------------------------------------------------------
| Administration — /admin (spec §92)
|--------------------------------------------------------------------------
|
| `platform` rejects any non-staff account before a controller runs, and
| `admin.2fa` holds administrators without an authenticator at the enrolment
| page (spec §9).
|
*/

Route::middleware(['auth', 'verified', 'platform', 'admin.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{organization}', [ClientController::class, 'show'])->name('clients.show');

        // Compliance (spec §41).
        Route::get('verification', [AdminVerificationController::class, 'index'])->name('verification.index');
        Route::get('verification/{profile}', [AdminVerificationController::class, 'show'])
            ->name('verification.show');
        Route::post('verification/{profile}/review', [AdminVerificationController::class, 'review'])
            ->name('verification.review');
        Route::get('verification/documents/{document}', VerificationDocumentDownloadController::class)
            ->name('verification.documents.download');

        /*
         * Finance (spec §41).
         *
         * Verifying a deposit and adjusting a balance are the two most
         * consequential actions on the platform; both are permission-gated and
         * audited, and both are above the maker-checker threshold at size.
         */
        Route::prefix('finance')->name('finance.')->group(function (): void {
            Route::get('deposits', [DepositController::class, 'index'])->name('deposits.index');
            Route::post('deposits/{payment}/verify', [DepositController::class, 'verify'])
                ->name('deposits.verify');
            Route::post('deposits/{payment}/reject', [DepositController::class, 'reject'])
                ->name('deposits.reject');
            Route::get('deposits/{payment}/proof', PaymentProofDownloadController::class)
                ->name('deposits.proof');

            Route::get('wallets', [FinanceController::class, 'wallets'])->name('wallets.index');
            Route::get('wallets/{wallet}', [FinanceController::class, 'showWallet'])
                ->name('wallets.show');
            Route::post('wallets/{wallet}/adjust', [FinanceController::class, 'adjust'])
                ->name('wallets.adjust');
            Route::post('wallets/{wallet}/refund', [FinanceController::class, 'refund'])
                ->name('wallets.refund');

            Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
            Route::post('approvals/{approvalRequest}/approve', [ApprovalController::class, 'approve'])
                ->name('approvals.approve');
            Route::post('approvals/{approvalRequest}/reject', [ApprovalController::class, 'reject'])
                ->name('approvals.reject');

            Route::get('exchange-rates', [FinanceController::class, 'exchangeRates'])
                ->name('exchange-rates.index');
            Route::post('exchange-rates', [FinanceController::class, 'publishRate'])
                ->name('exchange-rates.store');

            Route::get('pricing', [FinanceController::class, 'pricing'])->name('pricing.index');
        });

        /*
         * Advertising infrastructure (spec §17, §18).
         *
         * Platform-only throughout: the inventory is shared between clients,
         * and the pool rules decide whose money runs through which account.
         */
        Route::get('ad-accounts', [AdAccountController::class, 'index'])->name('ad-accounts.index');
        Route::post('ad-accounts', [AdAccountController::class, 'store'])->name('ad-accounts.store');
        Route::get('ad-accounts/{adAccount}', [AdAccountController::class, 'show'])->name('ad-accounts.show');
        Route::put('ad-accounts/{adAccount}', [AdAccountController::class, 'update'])->name('ad-accounts.update');
        Route::post('ad-accounts/{adAccount}/status', [AdAccountController::class, 'changeStatus'])
            ->name('ad-accounts.status');
        Route::post('ad-accounts/{adAccount}/refresh', [AdAccountController::class, 'refreshHealth'])
            ->name('ad-accounts.refresh');

        Route::get('ad-account-pools', [AdAccountPoolController::class, 'index'])
            ->name('ad-account-pools.index');
        Route::post('ad-account-pools', [AdAccountPoolController::class, 'store'])
            ->name('ad-account-pools.store');
        Route::get('ad-account-pools/{adAccountPool}', [AdAccountPoolController::class, 'show'])
            ->name('ad-account-pools.show');
        Route::put('ad-account-pools/{adAccountPool}', [AdAccountPoolController::class, 'update'])
            ->name('ad-account-pools.update');
        Route::post('ad-account-pools/{adAccountPool}/status', [AdAccountPoolController::class, 'changeStatus'])
            ->name('ad-account-pools.status');
        Route::post('ad-account-pools/{adAccountPool}/members', [AdAccountPoolController::class, 'addMember'])
            ->name('ad-account-pools.members.store');
        Route::delete('ad-account-pools/{adAccountPool}/members/{adAccount}', [AdAccountPoolController::class, 'removeMember'])
            ->name('ad-account-pools.members.destroy');

        /*
         * Campaign review (spec §21, §25).
         *
         * The approve route is where a client's money is committed, so the
         * policy behind it refuses a reviewer who submitted the campaign.
         */
        Route::get('campaigns', [CampaignReviewController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/{campaign}', [CampaignReviewController::class, 'show'])
            ->name('campaigns.show');
        Route::post('campaigns/{campaign}/approve', [CampaignReviewController::class, 'approve'])
            ->name('campaigns.approve');
        Route::post('campaigns/{campaign}/reject', [CampaignReviewController::class, 'reject'])
            ->name('campaigns.reject');
        Route::post('campaigns/{campaign}/pause', [CampaignReviewController::class, 'pause'])
            ->name('campaigns.pause');

        Route::get('creatives/{creative}/download', CreativeDownloadController::class)
            ->name('creatives.download');

        /*
         * Analytics and reconciliation (spec §38, §78).
         *
         * Settling a discrepancy records a decision; it never moves money.
         * That goes through a wallet adjustment, with its own approval.
         */
        Route::get('analytics', [ReconciliationController::class, 'overview'])
            ->name('analytics.overview');
        Route::get('analytics/reconciliation', [ReconciliationController::class, 'index'])
            ->name('analytics.reconciliation');
        Route::post('analytics/reconciliation/{spendReconciliation}/resolve', [ReconciliationController::class, 'resolve'])
            ->name('analytics.reconciliation.resolve');

        Route::get('analytics/exports/{export}/download', ReportDownloadController::class)
            ->name('analytics.exports.download');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });

// Reachable without a confirmed authenticator so an administrator can enrol.
Route::middleware(['auth', 'verified', 'platform'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('security/two-factor', TwoFactorSetupController::class)
            ->name('security.two-factor.setup');
    });
