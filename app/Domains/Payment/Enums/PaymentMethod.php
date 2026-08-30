<?php

declare(strict_types=1);

namespace App\Domains\Payment\Enums;

/**
 * How a client pays (spec §33).
 *
 * Gateway methods complete themselves through a callback the platform verifies
 * with the provider; manual methods wait for a person in finance to confirm the
 * money arrived. The enum knows which is which, so the flow is chosen from the
 * method rather than from a branch that could drift.
 */
enum PaymentMethod: string
{
    case Sslcommerz = 'SSLCOMMERZ';
    case Bkash = 'BKASH';
    case Nagad = 'NAGAD';
    case BankTransfer = 'BANK_TRANSFER';
    case ManualDeposit = 'MANUAL_DEPOSIT';

    public function label(): string
    {
        return match ($this) {
            self::Sslcommerz => 'Card or mobile banking (SSLCOMMERZ)',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::BankTransfer => 'Bank transfer',
            self::ManualDeposit => 'Manual deposit',
        };
    }

    /** Whether a person in finance confirms this payment rather than a gateway. */
    public function requiresManualVerification(): bool
    {
        return in_array($this, [self::BankTransfer, self::ManualDeposit], true);
    }

    /** Whether the client must supply their own transaction reference. */
    public function requiresExternalReference(): bool
    {
        return $this->requiresManualVerification();
    }

    /** Whether proof of payment must be attached. */
    public function requiresProof(): bool
    {
        return $this->requiresManualVerification();
    }

    public function providerKey(): ?string
    {
        return $this->requiresManualVerification() ? null : strtolower($this->value);
    }
}
