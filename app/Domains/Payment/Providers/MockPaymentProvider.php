<?php

declare(strict_types=1);

namespace App\Domains\Payment\Providers;

use App\Domains\Payment\Contracts\PaymentProvider;
use App\Domains\Payment\DTOs\PaymentIntent;
use App\Domains\Payment\DTOs\ProviderVerification;
use App\Domains\Payment\Models\Payment;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A gateway stand-in for development and tests (spec §95).
 *
 * Lets the whole payment flow — intent, redirect, callback, verification,
 * ledger credit — be exercised without a live merchant account, which is what
 * makes it possible to build and test billing before gateway approval comes
 * through.
 *
 * Refuses to run in production: a mock that silently confirms payments in a
 * live environment would be a way to mint balance.
 */
final class MockPaymentProvider implements PaymentProvider
{
    /** @var array<string, bool> */
    private array $outcomes = [];

    public function __construct(private readonly string $key = 'mock')
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock payment provider must never be used in production.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    /** Test hook: decide in advance what this provider will report. */
    public function willReport(Payment $payment, bool $successful): void
    {
        $this->outcomes[$payment->reference] = $successful;
    }

    public function createIntent(Payment $payment, array $context = []): PaymentIntent
    {
        return new PaymentIntent(
            redirectUrl: route('client.wallet.payments.return', $payment->public_id),
            providerReference: 'MOCK-'.Str::upper(Str::random(10)),
            metadata: ['mock' => true],
        );
    }

    public function verify(Payment $payment): ProviderVerification
    {
        $successful = $this->outcomes[$payment->reference] ?? true;

        if (! $successful) {
            return ProviderVerification::failed('The mock provider was told to decline this payment.');
        }

        return new ProviderVerification(
            successful: true,
            // Echoed back exactly, so the amount check in the verifier is
            // exercised rather than bypassed.
            amount: $payment->amountMoney(),
            providerReference: $payment->provider_reference ?? 'MOCK-'.Str::upper(Str::random(10)),
            raw: ['mock' => true],
        );
    }

    public function verifySignature(array $payload, array $headers): bool
    {
        return true;
    }
}
