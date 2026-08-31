<?php

declare(strict_types=1);

namespace App\Domains\System\Enums;

use App\Domains\Identity\Enums\Permission;

/**
 * Actions that can require maker-checker approval (spec §25).
 *
 * Each case names the permission an approver must hold and how many approvals
 * it needs, so the control is described in one place rather than scattered
 * across the call sites it governs.
 */
enum ApprovableAction: string
{
    case WalletAdjustment = 'WALLET_ADJUSTMENT';
    case Refund = 'REFUND';
    case ExchangeRateChange = 'EXCHANGE_RATE_CHANGE';
    case CampaignApproval = 'CAMPAIGN_APPROVAL';

    public function label(): string
    {
        return match ($this) {
            self::WalletAdjustment => 'Wallet adjustment',
            self::Refund => 'Refund',
            self::ExchangeRateChange => 'Exchange rate change',
            self::CampaignApproval => 'Campaign approval',
        };
    }

    /** The permission a second pair of eyes must hold to sign this off. */
    public function approvalPermission(): Permission
    {
        return match ($this) {
            self::WalletAdjustment => Permission::WalletAdjust,
            self::Refund => Permission::WalletRefund,
            self::ExchangeRateChange => Permission::ExchangeRatesManage,
            self::CampaignApproval => Permission::CampaignsApprove,
        };
    }

    /**
     * The value at or above which this action needs approval, in minor units.
     * Null means the action always needs it regardless of size.
     */
    public function thresholdMinorUnits(): ?int
    {
        return match ($this) {
            self::WalletAdjustment => (int) config('platform.finance.maker_checker.wallet_adjustment_minor'),
            self::Refund => (int) config('platform.finance.maker_checker.refund_minor'),
            self::ExchangeRateChange => null,
            self::CampaignApproval => (int) config('platform.finance.maker_checker.campaign_budget_minor'),
        };
    }

    /**
     * How many separate approvers are needed. Spec §25 asks for a second
     * approval on the largest movements; the threshold for that is ten times
     * the ordinary one.
     */
    public function requiredApprovals(?int $amountMinorUnits): int
    {
        $threshold = $this->thresholdMinorUnits();

        if ($threshold === null || $amountMinorUnits === null) {
            return 1;
        }

        return $amountMinorUnits >= $threshold * 10 ? 2 : 1;
    }

    /** Whether an action of this size must go through maker-checker at all. */
    public function requiresApproval(?int $amountMinorUnits): bool
    {
        $threshold = $this->thresholdMinorUnits();

        if ($threshold === null) {
            return true;
        }

        return $amountMinorUnits !== null && $amountMinorUnits >= $threshold;
    }
}
