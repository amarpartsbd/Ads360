<?php

declare(strict_types=1);

namespace App\Domains\Payment\DTOs;

use App\Support\Values\Money;

/**
 * What a provider says about a payment.
 *
 * `amount` is carried so the caller can check the provider's figure against the
 * platform's own record: a gateway reporting success for a different amount is
 * not a success (spec §33).
 */
final readonly class ProviderVerification
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $successful,
        public ?Money $amount = null,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(successful: false, failureReason: $reason, raw: $raw);
    }
}
