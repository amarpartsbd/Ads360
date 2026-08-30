<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\DTOs\FeeLine;
use App\Domains\Billing\DTOs\PricedAmount;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use RuntimeException;

/**
 * Works out what a client is charged (spec §36).
 *
 * Resolution walks the hierarchy from most specific to least — client override,
 * then tenant plan, then platform default — and takes the first active plan it
 * finds. There is no merging: a plan is a complete statement of what a client
 * pays, so an override is easy to reason about and impossible to half-apply.
 *
 * Every result carries a snapshot of the plan that produced it, which is what
 * gets stored with the transaction. An invoice from six months ago then
 * explains itself even if the plan has since changed.
 */
final class PricingEngine
{
    /**
     * The plan that applies to an organization.
     *
     * @throws RuntimeException when no plan resolves, including the default
     */
    public function planFor(Organization $organization): PricingPlan
    {
        $plan = PricingPlan::query()
            ->with(['rules' => fn ($query) => $query->where('is_active', true)->orderBy('priority')])
            ->where('is_active', true)
            ->where(function ($query) use ($organization): void {
                $query->where(function ($scoped) use ($organization): void {
                    $scoped->where('scope', PricingScope::Organization)
                        ->where('organization_id', $organization->getKey());
                })->orWhere(function ($scoped) use ($organization): void {
                    $scoped->where('scope', PricingScope::Tenant)
                        ->where('tenant_id', $organization->tenant_id);
                })->orWhere(function ($scoped): void {
                    $scoped->where('scope', PricingScope::Platform)->where('is_default', true);
                });
            })
            ->get()
            // Ordering in PHP rather than SQL: the comparison is on the enum's
            // own notion of specificity, which belongs with the enum.
            ->sortByDesc(fn (PricingPlan $candidate): int => $candidate->scope->specificity())
            ->first();

        if ($plan === null) {
            throw new RuntimeException(
                'No pricing plan applies to this organization and no platform default is configured.'
            );
        }

        return $plan;
    }

    /**
     * Price an amount for an organization.
     *
     * Fees are computed on the base amount in rule priority order; tax is
     * computed last, on the fee subtotal, because tax applies to what the
     * platform charges rather than to the client's ad budget.
     */
    public function price(Organization $organization, Money $base): PricedAmount
    {
        return $this->priceWithPlan($this->planFor($organization), $base);
    }

    public function priceWithPlan(PricingPlan $plan, Money $base): PricedAmount
    {
        if ($plan->currency !== $base->currency->code) {
            throw new RuntimeException(
                "Pricing plan [{$plan->name}] is in {$plan->currency} but the amount is in {$base->currency->code}."
            );
        }

        $fees = [];
        $feeTotal = Money::zero($base->currency);

        $rules = $plan->rules->sortBy('priority');

        foreach ($rules as $rule) {
            if ($rule->fee_type->appliesToFeeSubtotal()) {
                continue;
            }

            if (! $rule->appliesTo($base)) {
                continue;
            }

            $amount = $rule->calculate($base);

            if ($amount->isZero()) {
                continue;
            }

            $fees[] = new FeeLine(
                type: $rule->fee_type,
                amount: $amount,
                description: $rule->fee_type->label(),
                ruleSnapshot: $rule->snapshot(),
            );

            $feeTotal = $feeTotal->plus($amount);
        }

        // Tax second pass, over the fees the first pass produced.
        foreach ($rules as $rule) {
            if (! $rule->fee_type->appliesToFeeSubtotal() || ! $rule->is_active) {
                continue;
            }

            $amount = $rule->calculate($feeTotal);

            if ($amount->isZero()) {
                continue;
            }

            $fees[] = new FeeLine(
                type: $rule->fee_type,
                amount: $amount,
                description: $rule->fee_type->label(),
                ruleSnapshot: $rule->snapshot(),
            );

            $feeTotal = $feeTotal->plus($amount);
        }

        return new PricedAmount(
            base: $base,
            fees: $fees,
            feeTotal: $feeTotal,
            total: $base->plus($feeTotal),
            pricingSnapshot: $plan->snapshot(),
        );
    }
}
