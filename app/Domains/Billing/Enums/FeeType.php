<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Domains\Wallet\Enums\LedgerEntryType;

/**
 * The charges the platform can levy (spec §36).
 *
 * Each type knows which ledger entry it becomes, so a fee is never posted under
 * the wrong heading.
 */
enum FeeType: string
{
    case PlatformFee = 'PLATFORM_FEE';
    case ManagementFee = 'MANAGEMENT_FEE';
    case CampaignSetupFee = 'CAMPAIGN_SETUP_FEE';
    case CurrencyMarkup = 'CURRENCY_MARKUP';
    case Tax = 'TAX';
    case Subscription = 'SUBSCRIPTION';

    public function label(): string
    {
        return match ($this) {
            self::PlatformFee => 'Platform fee',
            self::ManagementFee => 'Management fee',
            self::CampaignSetupFee => 'Campaign setup fee',
            self::CurrencyMarkup => 'Currency markup',
            self::Tax => 'Tax',
            self::Subscription => 'Subscription',
        };
    }

    public function ledgerType(): LedgerEntryType
    {
        return match ($this) {
            self::PlatformFee, self::CampaignSetupFee, self::CurrencyMarkup,
            self::Subscription => LedgerEntryType::ServiceFee,
            self::ManagementFee => LedgerEntryType::ManagementFee,
            self::Tax => LedgerEntryType::Tax,
        };
    }

    /**
     * Tax is charged on the fees rather than on the ad budget, so it is
     * computed last, over the subtotal the other rules produced.
     */
    public function appliesToFeeSubtotal(): bool
    {
        return $this === self::Tax;
    }
}
