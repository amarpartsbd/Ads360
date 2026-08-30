<?php

declare(strict_types=1);

namespace App\Domains\Payment\DTOs;

/**
 * Where to send a client to complete a payment.
 */
final readonly class PaymentIntent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $redirectUrl,
        public ?string $providerReference = null,
        public array $metadata = [],
    ) {}
}
