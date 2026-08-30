<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\FeeType;
use App\Domains\Billing\Enums\PricingCalculation;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge within a pricing plan (spec §36).
 *
 * @property FeeType $fee_type
 * @property PricingCalculation $calculation
 */
class PricingRule extends Model
{
    use HasPublicId;

    protected $fillable = [
        'pricing_plan_id',
        'fee_type',
        'calculation',
        'percentage',
        'fixed_amount',
        'minimum_amount',
        'maximum_amount',
        'applies_from_amount',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'calculation' => PricingCalculation::class,
            // Kept as a string: a percentage multiplies money, and Money only
            // accepts exact decimal strings.
            'percentage' => 'string',
            'fixed_amount' => 'integer',
            'minimum_amount' => 'integer',
            'maximum_amount' => 'integer',
            'applies_from_amount' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PricingPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }

    public function appliesTo(Money $base): bool
    {
        return $this->is_active && $base->minorUnits >= $this->applies_from_amount;
    }

    /**
     * Compute this rule's charge on a base amount.
     *
     * Rounding is half-up and explicit. Bounds are applied after the
     * calculation, so a percentage fee can carry a floor and a ceiling without
     * the caller doing arithmetic of its own.
     */
    public function calculate(Money $base): Money
    {
        $currency = $base->currency;

        $fee = match ($this->calculation) {
            PricingCalculation::Percentage => $base->percentage(
                $this->percentage,
                Money::ROUND_HALF_UP,
            ),
            PricingCalculation::Fixed => Money::ofMinor((int) $this->fixed_amount, $currency),
        };

        if ($this->minimum_amount !== null) {
            $minimum = Money::ofMinor($this->minimum_amount, $currency);
            $fee = $fee->lessThan($minimum) ? $minimum : $fee;
        }

        if ($this->maximum_amount !== null) {
            $maximum = Money::ofMinor($this->maximum_amount, $currency);
            $fee = $fee->greaterThan($maximum) ? $maximum : $fee;
        }

        return $fee;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'rule_id' => $this->public_id,
            'fee_type' => $this->fee_type->value,
            'calculation' => $this->calculation->value,
            'percentage' => $this->percentage,
            'fixed_amount' => $this->fixed_amount,
            'minimum_amount' => $this->minimum_amount,
            'maximum_amount' => $this->maximum_amount,
            'applies_from_amount' => $this->applies_from_amount,
        ];
    }
}
