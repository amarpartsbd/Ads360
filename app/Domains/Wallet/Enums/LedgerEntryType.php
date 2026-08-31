<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Enums;

/**
 * What a ledger entry represents (spec §31).
 *
 * Each type declares which side of the wallet it moves, so no call site has to
 * remember whether a service fee is a debit — the type says so, and the ledger
 * writer reads it.
 */
enum LedgerEntryType: string
{
    case Deposit = 'DEPOSIT';
    case CampaignSpend = 'CAMPAIGN_SPEND';
    case ServiceFee = 'SERVICE_FEE';
    case ManagementFee = 'MANAGEMENT_FEE';
    case Tax = 'TAX';
    case Reserve = 'RESERVE';
    case Release = 'RELEASE';
    case Refund = 'REFUND';
    case Reversal = 'REVERSAL';
    case Adjustment = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::CampaignSpend => 'Campaign spend',
            self::ServiceFee => 'Service fee',
            self::ManagementFee => 'Management fee',
            self::Tax => 'Tax',
            self::Reserve => 'Budget reserved',
            self::Release => 'Budget released',
            self::Refund => 'Refund',
            self::Reversal => 'Reversal',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Whether this type moves funds between available and reserved rather than
     * in or out of the wallet. A reservation changes what the client may spend
     * without changing what they hold.
     */
    public function movesReservedBalance(): bool
    {
        return in_array($this, [self::Reserve, self::Release], true);
    }

    /**
     * Whether an entry of this type normally increases available balance.
     * Used only for presentation; the sign is carried by the debit and credit
     * columns, which are the authority.
     */
    public function isNormallyCredit(): bool
    {
        return in_array($this, [self::Deposit, self::Release, self::Refund], true);
    }

    /**
     * Whether an amount of this type may be drawn out of a hold.
     *
     * Capture takes money from a reservation and spends it, so only the types
     * that represent something being charged qualify. Capturing a DEPOSIT or a
     * RELEASE would be adding to the balance while claiming to spend from it.
     */
    public function isCapturable(): bool
    {
        return in_array($this, [
            self::CampaignSpend,
            self::ServiceFee,
            self::ManagementFee,
            self::Tax,
        ], true);
    }

    /**
     * Types a reversal may be written against. Reversing a reversal would make
     * the history impossible to read, and a reservation is undone by releasing
     * it rather than by reversing the entry.
     */
    public function isReversible(): bool
    {
        return ! in_array($this, [self::Reversal, self::Reserve, self::Release], true);
    }
}
