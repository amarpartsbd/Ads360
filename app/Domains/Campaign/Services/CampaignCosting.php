<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Billing\DTOs\PricedAmount;
use App\Domains\Billing\Services\PricingEngine;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;

/**
 * What a campaign costs (spec §22, Rule 8).
 *
 * Everything here happens server-side. The browser is given formatted strings
 * to display and never the ingredients to recompute a total — a client who
 * could submit their own fee calculation could submit a smaller one.
 *
 * The price is quoted while the campaign is a draft and frozen at submission.
 * Between those two moments a pricing plan could change, and honouring the
 * quote the client agreed to matters more than charging today's rate.
 */
final class CampaignCosting
{
    public function __construct(private readonly PricingEngine $pricing) {}

    /**
     * Price the campaign's whole commitment: the advertising budget plus the
     * platform's fees on top of it.
     *
     * Fees are added to the budget rather than taken out of it, so the amount
     * that reaches the provider is the amount the client asked to spend
     * (spec §22).
     */
    public function quote(Campaign $campaign): PricedAmount
    {
        return $this->pricing->price($this->organizationFor($campaign), $campaign->committedBudget());
    }

    /** Budget plus fees — what has to be held against the wallet. */
    public function total(Campaign $campaign): Money
    {
        return $this->quote($campaign)->total;
    }

    /**
     * Re-price using the plan snapshot stored at submission, so a campaign
     * already in review is explained with the numbers it was submitted under
     * rather than today's.
     *
     * @return array<string, mixed>
     */
    public function storedBreakdown(Campaign $campaign): array
    {
        if ($campaign->pricing_snapshot === [] || $campaign->pricing_snapshot === null) {
            return $this->quote($campaign)->toArray();
        }

        return [
            'base' => $campaign->budget()->jsonSerialize(),
            'committed' => $campaign->committedBudget()->jsonSerialize(),
            'feeTotal' => $campaign->feeTotal()->jsonSerialize(),
            'total' => $campaign->chargedTotal()->jsonSerialize(),
            'fees' => $campaign->pricing_snapshot['fees'] ?? [],
            'quotedAt' => $campaign->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * Read without the tenant scope and filtered by key: costing runs from the
     * approval pipeline and from queued jobs, where no tenant is bound and the
     * scope would return nothing (spec §7).
     */
    private function organizationFor(Campaign $campaign): Organization
    {
        return Organization::query()
            ->withoutGlobalScopes()
            ->whereKey($campaign->organization_id)
            ->firstOrFail();
    }
}
