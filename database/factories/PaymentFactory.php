<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Payment\Enums\PaymentMethod;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => Payment::generateReference(),
            'method' => PaymentMethod::BankTransfer,
            'amount' => 50_000_00,
            'currency' => 'BDT',
            'status' => PaymentStatus::AwaitingVerification,
            'external_reference' => 'TXN-'.$this->faker->unique()->numerify('##########'),
            'submitted_at' => now(),
            'metadata' => [],
        ];
    }

    public function forWallet(Wallet $wallet): static
    {
        return $this->state(fn (): array => [
            'wallet_id' => $wallet->getKey(),
            'organization_id' => $wallet->organization_id,
            'tenant_id' => $wallet->tenant_id,
            'currency' => $wallet->currency,
        ]);
    }

    public function amount(int $minorUnits): static
    {
        return $this->state(fn (): array => ['amount' => $minorUnits]);
    }

    public function gateway(PaymentMethod $method = PaymentMethod::Sslcommerz): static
    {
        return $this->state(fn (): array => [
            'method' => $method,
            'provider' => $method->providerKey(),
            'status' => PaymentStatus::Pending,
            'external_reference' => null,
        ]);
    }
}
