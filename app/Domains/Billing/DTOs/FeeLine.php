<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use App\Domains\Billing\Enums\FeeType;
use App\Support\Values\Money;

/**
 * One charge produced by the pricing engine.
 */
final readonly class FeeLine
{
    /**
     * @param  array<string, mixed>  $ruleSnapshot  the rule as it stood when applied
     */
    public function __construct(
        public FeeType $type,
        public Money $amount,
        public string $description,
        public array $ruleSnapshot,
    ) {}
}
