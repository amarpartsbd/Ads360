<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client dashboard (spec §13).
 *
 * Phase 1 shows real onboarding state. The spend, campaign and performance
 * cards belong to the finance and analytics modules; each is marked as awaiting
 * its module rather than shown as a fabricated zero.
 */
final class ClientDashboardController
{
    public function __invoke(TenantContext $context): Response
    {
        $organization = $context->requireOrganization();
        $organization->loadMissing('verificationProfile');

        $verification = $organization->verificationProfile?->status ?? VerificationStatus::NotSubmitted;

        return Inertia::render('Client/Dashboard', [
            'organization' => [
                'name' => $organization->name,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'currency' => $organization->default_currency,
            ],
            'verification' => [
                'status' => $verification->value,
                'statusLabel' => $verification->label(),
                'description' => $verification->description(),
                'actionable' => $verification->isEditableByClient(),
                'url' => route('client.verification.show'),
            ],
            'onboarding' => [
                'verified' => $verification->isVerified(),
                'steps' => $this->onboardingSteps($organization, $verification),
            ],
        ]);
    }

    /**
     * @return list<array{key: string, label: string, complete: bool, available: bool, href: string|null}>
     */
    private function onboardingSteps(Organization $organization, VerificationStatus $verification): array
    {
        $verified = $verification->isVerified();

        return [
            [
                'key' => 'register',
                'label' => 'Create your account',
                'complete' => true,
                'available' => true,
                'href' => null,
            ],
            [
                'key' => 'verify',
                'label' => 'Verify your business',
                'complete' => $verified,
                'available' => true,
                'href' => route('client.verification.show'),
            ],
            [
                'key' => 'team',
                'label' => 'Invite your team',
                'complete' => $organization->activeMembers()->count() > 1,
                'available' => true,
                'href' => route('client.team.index'),
            ],
            // The remaining steps unlock with their modules, and each is
            // gated on verification regardless.
            [
                'key' => 'connect',
                'label' => 'Connect advertising assets',
                'complete' => false,
                'available' => false,
                'href' => null,
            ],
            [
                'key' => 'fund',
                'label' => 'Add balance',
                'complete' => false,
                'available' => false,
                'href' => null,
            ],
            [
                'key' => 'campaign',
                'label' => 'Create your first campaign',
                'complete' => false,
                'available' => false,
                'href' => null,
            ],
        ];
    }
}
