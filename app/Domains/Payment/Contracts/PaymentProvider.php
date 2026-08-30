<?php

declare(strict_types=1);

namespace App\Domains\Payment\Contracts;

use App\Domains\Payment\DTOs\PaymentIntent;
use App\Domains\Payment\DTOs\ProviderVerification;
use App\Domains\Payment\Models\Payment;

/**
 * A payment gateway (spec §33).
 *
 * The abstraction exists so nothing above it knows which gateway is in use.
 * The important method is `verify()`: the platform decides a payment succeeded
 * by asking the provider, never by trusting a browser redirect that says so.
 */
interface PaymentProvider
{
    /** The key this provider is registered under. */
    public function key(): string;

    /**
     * Begin a charge and return where to send the client.
     *
     * @param  array<string, mixed>  $context
     */
    public function createIntent(Payment $payment, array $context = []): PaymentIntent;

    /**
     * Ask the provider what actually happened to a payment.
     *
     * This is the authority. A callback or webhook only tells the platform to
     * come and ask; the answer comes from here (spec §33).
     */
    public function verify(Payment $payment): ProviderVerification;

    /**
     * Whether an inbound callback genuinely came from this provider.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function verifySignature(array $payload, array $headers): bool;
}
