<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use App\Support\Values\Money;

/**
 * What a client will actually be charged for something, broken down.
 *
 * Produced entirely server-side. The interface renders these numbers; it never
 * computes them (spec §21 Step 4, Rule 8).
 */
final readonly class PricedAmount
{
    /**
     * @param  list<FeeLine>  $fees
     * @param  array<string, mixed>  $pricingSnapshot  the plan as it stood when priced
     */
    public function __construct(
        public Money $base,
        public array $fees,
        public Money $feeTotal,
        public Money $total,
        public array $pricingSnapshot,
    ) {}

    /**
     * A shape the interface can render directly, with every amount already
     * formatted — so no money arithmetic happens in the browser.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base' => $this->base->jsonSerialize(),
            'fees' => array_map(static fn (FeeLine $fee): array => [
                'type' => $fee->type->value,
                'label' => $fee->type->label(),
                'description' => $fee->description,
                'amount' => $fee->amount->jsonSerialize(),
            ], $this->fees),
            'feeTotal' => $this->feeTotal->jsonSerialize(),
            'total' => $this->total->jsonSerialize(),
        ];
    }
}
