<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Tenant\Services\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client dashboard (spec §13).
 *
 * Phase 0 renders the shell with the account state that already exists. The
 * spend, campaign and performance cards are filled in by the finance and
 * analytics modules of later phases; each is marked as awaiting its module
 * rather than shown as a fabricated zero.
 */
final class ClientDashboardController
{
    public function __invoke(TenantContext $context): Response
    {
        $organization = $context->requireOrganization();

        return Inertia::render('Client/Dashboard', [
            'organization' => [
                'name' => $organization->name,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'currency' => $organization->default_currency,
            ],
            'onboarding' => [
                'verified' => $organization->isOperational(),
                'steps' => $this->onboardingSteps($organization->isOperational()),
            ],
        ]);
    }

    /**
     * @return list<array{key: string, label: string, complete: bool, available: bool}>
     */
    private function onboardingSteps(bool $verified): array
    {
        return [
            ['key' => 'register', 'label' => 'Create your account', 'complete' => true, 'available' => true],
            ['key' => 'verify', 'label' => 'Verify your business', 'complete' => $verified, 'available' => true],
            ['key' => 'connect', 'label' => 'Connect advertising assets', 'complete' => false, 'available' => false],
            ['key' => 'fund', 'label' => 'Add balance', 'complete' => false, 'available' => false],
            ['key' => 'campaign', 'label' => 'Create your first campaign', 'complete' => false, 'available' => false],
        ];
    }
}
